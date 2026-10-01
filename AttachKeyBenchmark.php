<?php

namespace Winter\Storm\Benchmarks;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Winter\Storm\Database\Attach\File;
use Winter\Storm\Database\MemoryCache;
use Winter\Storm\Database\Model;
use Winter\Storm\Database\Schema\Blueprint;
use Winter\Storm\Tests\TestCase;

class BenchUser extends Model
{
    public $table = 'bench_users';

    protected $guarded = [];

    public $attachOne = [
        'avatar' => File::class,
    ];
}

/**
 * Times an attachOne lookup and an eager load of 25 parents against a files table of growing size.
 *
 * Env: BENCH_LABEL (output name), BENCH_OUT (output dir), BENCH_SIZES (comma list), BENCH_ITERATIONS,
 * BENCH_DB_HOST, BENCH_DB_PORT, BENCH_DB_USER, BENCH_DB_PASSWORD. Drops and recreates tables in the
 * `storm_bench` database.
 */
class AttachKeyBenchmark extends TestCase
{
    private const DATABASE = 'storm_bench';
    private const USERS = 25;

    private function useBenchConnection(): void
    {
        $host = getenv('BENCH_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('BENCH_DB_PORT') ?: 3306);
        $user = getenv('BENCH_DB_USER') ?: 'root';
        $password = getenv('BENCH_DB_PASSWORD') ?: '';

        (new \PDO("mysql:host=$host;port=$port", $user, $password))
            ->exec('CREATE DATABASE IF NOT EXISTS ' . self::DATABASE);

        config(['database.connections.bench' => [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => self::DATABASE,
            'username' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]]);
        config(['database.default' => 'bench']);
        DB::setDefaultConnection('bench');
    }

    public function testBenchmark(): void
    {
        $sizes = array_map('intval', explode(',', getenv('BENCH_SIZES') ?: '1000,10000,50000,100000,250000,500000,1000000'));
        $iterations = (int) (getenv('BENCH_ITERATIONS') ?: 25);

        $this->useBenchConnection();
        // Models memoise identical selects per request; every timed run must reach the database.
        MemoryCache::instance()->enabled(false);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('mysql', (new File())->getConnection()->getDriverName());

        $this->createTables();
        for ($i = 1; $i <= self::USERS; $i++) {
            BenchUser::create(['name' => 'User ' . $i]);
        }
        $user = BenchUser::find(1);

        mt_srand(42);
        $rows = 0;
        $results = [];

        foreach ($sizes as $size) {
            $rows = $this->growFilesTo($rows, $size);

            $lookup = $this->measure(
                fn () => $user->avatar()->get()->pluck('id')->all(),
                $iterations,
            );
            $eager = $this->measure(
                fn () => BenchUser::with('avatar')->orderBy('id')->get()->mapWithKeys(fn ($u) => [$u->id => $u->avatar?->id])->all(),
                $iterations,
            );
            $results[] = ['size' => $size, 'lookup' => $lookup, 'eager' => $eager];

            fwrite(STDERR, sprintf("%9d rows: %8.2f ms (single lookup), %8.2f ms (eager load, %d parents)\n",
                $size, $lookup['median_ms'], $eager['median_ms'], self::USERS));
        }

        $out = (getenv('BENCH_OUT') ?: sys_get_temp_dir()) . '/' . (getenv('BENCH_LABEL') ?: 'run') . '.json';
        file_put_contents($out, json_encode([
            'mysql' => DB::selectOne('select version() as v')->v,
            'php' => PHP_VERSION,
            'iterations' => $iterations,
            'results' => $results,
        ], JSON_PRETTY_PRINT));

        $this->assertNotEmpty($results);
    }

    /**
     * Times $run, then explains the query it sends to the files table.
     */
    private function measure(Closure $run, int $iterations): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $run();
        $firstMs = (hrtime(true) - $start) / 1e6;
        $filesQuery = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], '`files`'));
        DB::disableQueryLog();

        // Spend at most ~4s per measurement, but always at least 3 timed runs.
        $iterations = max(3, min($iterations, (int) floor(4000 / max($firstMs, 0.001))));
        if ($iterations > 3) {
            $run();
        }

        $timings = [];
        DB::flushQueryLog();
        DB::enableQueryLog();
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $result = $run();
            $timings[] = (hrtime(true) - $start) / 1e6;
        }
        $sent = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '`files`'))->count();
        DB::flushQueryLog();
        DB::disableQueryLog();
        $this->assertSame($iterations, $sent, 'every timed run must query the files table');
        sort($timings);

        $explain = DB::select('explain ' . $filesQuery['query'], $filesQuery['bindings'])[0];

        return [
            'median_ms' => $timings[intdiv(count($timings), 2)],
            'p95_ms' => $timings[(int) floor(0.95 * (count($timings) - 1))],
            'runs' => count($timings),
            'result' => $result,
            'sql' => $filesQuery['query'],
            'binding_types' => array_map('gettype', $filesQuery['bindings']),
            'explain' => ['type' => $explain->type, 'key' => $explain->key, 'rows' => (int) $explain->rows],
        ];
    }

    private function createTables(): void
    {
        Schema::dropIfExists('files');
        Schema::dropIfExists('deferred_bindings');
        Schema::dropIfExists('bench_users');

        Schema::create('bench_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        // Same columns and indexes as Winter's system_files.
        Schema::create('files', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('disk_name');
            $table->string('file_name');
            $table->integer('file_size');
            $table->string('content_type');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('field')->nullable()->index();
            $table->string('attachment_id')->index()->nullable();
            $table->string('attachment_type')->index()->nullable();
            $table->boolean('is_public')->default(true);
            $table->integer('sort_order')->nullable();
            $table->mediumText('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function growFilesTo(int $current, int $target): int
    {
        // 40 model classes and 12 field names; the parents' class is one of them (~2.5% of rows).
        $types = array_map(fn ($i) => sprintf('App\Models\Model%02d', $i), range(1, 39));
        $types[] = BenchUser::class;
        $fields = ['images', 'avatar', 'documents', 'gallery', 'cover', 'thumbnail', 'logo', 'pdf', 'banner', 'icon', 'manual', 'attachments'];
        $now = now()->toDateTimeString();

        // On the first fill, give each of the parents one avatar, spread through the table.
        $avatars = [];
        if ($current === 0) {
            for ($u = 1; $u <= self::USERS; $u++) {
                $avatars[intdiv($target * $u, self::USERS + 1)] = $u;
            }
        }

        for ($id = $current + 1; $id <= $target;) {
            $chunk = [];
            for ($n = 0; $n < 5000 && $id <= $target; $n++, $id++) {
                $owner = $avatars[$id] ?? null;
                $chunk[] = [
                    'disk_name' => md5((string) $id) . '.png',
                    'file_name' => 'file-' . $id . '.png',
                    'file_size' => mt_rand(1000, 900000),
                    'content_type' => 'image/png',
                    'field' => $owner ? 'avatar' : $fields[mt_rand(0, 11)],
                    'attachment_id' => $owner ? (string) $owner : (string) mt_rand(self::USERS + 1, max(self::USERS + 1, intdiv($target, 3))),
                    'attachment_type' => $owner ? BenchUser::class : $types[mt_rand(0, 39)],
                    'is_public' => 1,
                    'sort_order' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('files')->insert($chunk);
        }

        DB::statement('analyze table files');

        return $target;
    }
}

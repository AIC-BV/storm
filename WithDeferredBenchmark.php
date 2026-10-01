<?php

namespace Winter\Storm\Benchmarks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Winter\Storm\Database\Attach\File;
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
 * Times AttachOne::withDeferred()->get() against a files table of growing size.
 *
 * Env: BENCH_LABEL (output name), BENCH_OUT (output dir), BENCH_SIZES (comma list), BENCH_ITERATIONS,
 * BENCH_DB_HOST, BENCH_DB_PORT, BENCH_DB_USER, BENCH_DB_PASSWORD. Drops and recreates tables in the
 * `storm_bench` database.
 */
class WithDeferredBenchmark extends TestCase
{
    private const DATABASE = 'storm_bench';
    private const SESSION_KEY = 'bench-session';

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
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('mysql', (new File())->getConnection()->getDriverName());

        $this->createTables();
        $user = BenchUser::create(['name' => 'Target']);
        $this->seedNoiseBindings();

        mt_srand(42);
        $rows = 0;
        $results = [];

        foreach ($sizes as $size) {
            $rows = $this->growFilesTo($rows, $size, $user);

            $results[] = [
                'size' => $size,
                'nothing_deferred' => $this->measure($user, $iterations),
                'binding_pending' => $this->measureWithPendingBinding($user, $iterations),
            ];

            fwrite(STDERR, sprintf("%9d rows: %8.2f ms (nothing deferred), %8.2f ms (binding pending)\n",
                $size,
                $results[array_key_last($results)]['nothing_deferred']['median_ms'],
                $results[array_key_last($results)]['binding_pending']['median_ms'],
            ));
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

    private function measure(BenchUser $user, int $iterations): array
    {
        $query = fn () => $user->avatar()->withDeferred(self::SESSION_KEY);

        $start = hrtime(true);
        $query()->get();
        $firstMs = (hrtime(true) - $start) / 1e6;

        // Spend at most ~4s per measurement, but always at least 3 timed runs.
        $iterations = max(3, min($iterations, (int) floor(4000 / max($firstMs, 0.001))));
        if ($iterations > 3) {
            $query()->get();
        }

        $timings = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $files = $query()->get();
            $timings[] = (hrtime(true) - $start) / 1e6;
        }
        sort($timings);

        $builder = $query();
        $explain = DB::select('explain ' . $builder->toSql(), $builder->getBindings())[0];

        return [
            'median_ms' => $timings[intdiv(count($timings), 2)],
            'p95_ms' => $timings[(int) floor(0.95 * (count($timings) - 1))],
            'runs' => count($timings),
            'file_ids' => $files->pluck('id')->all(),
            'explain' => ['type' => $explain->type, 'key' => $explain->key, 'rows' => (int) $explain->rows],
            'binding_types' => array_map('gettype', $builder->getBindings()),
        ];
    }

    private function measureWithPendingBinding(BenchUser $user, int $iterations): array
    {
        $file = new File();
        $file->forceFill([
            'disk_name' => 'pending.png', 'file_name' => 'pending.png', 'file_size' => 1,
            'content_type' => 'image/png', 'is_public' => true,
        ])->save();
        $user->avatar()->add($file, self::SESSION_KEY);

        $result = $this->measure($user, $iterations);

        $user->avatar()->remove($file, self::SESSION_KEY);
        DB::table('deferred_bindings')->where('session_key', self::SESSION_KEY)->delete();
        DB::table('files')->where('id', $file->id)->delete();

        return $result;
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

        // Same columns and indexes as Winter's deferred_bindings (incl. the 2017 session_key index).
        Schema::create('deferred_bindings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('master_type')->index();
            $table->string('master_field')->index();
            $table->string('slave_type')->index();
            $table->string('slave_id')->index();
            $table->string('session_key')->index();
            $table->boolean('is_bind')->default(true);
            $table->mediumText('pivot_data')->nullable();
            $table->timestamps();
        });
    }

    private function seedNoiseBindings(): void
    {
        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = [
                'master_type' => BenchUser::class, 'master_field' => 'avatar',
                'slave_type' => File::class, 'slave_id' => (string) $i,
                'session_key' => 'other-session-' . $i, 'is_bind' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('deferred_bindings')->insert($rows);
    }

    private function growFilesTo(int $current, int $target, BenchUser $user): int
    {
        // 40 model classes and 12 field names; the target's class is one of them (~2.5% of rows).
        $types = array_map(fn ($i) => sprintf('App\Models\Model%02d', $i), range(1, 39));
        $types[] = BenchUser::class;
        $fields = ['images', 'avatar', 'documents', 'gallery', 'cover', 'thumbnail', 'logo', 'pdf', 'banner', 'icon', 'manual', 'attachments'];
        $now = now()->toDateTimeString();
        $targetRow = intdiv($target, 2);
        $placeTarget = $current === 0;

        for ($id = $current + 1; $id <= $target;) {
            $chunk = [];
            for ($n = 0; $n < 5000 && $id <= $target; $n++, $id++) {
                $isTarget = $placeTarget && $id === max(1, $targetRow);
                $chunk[] = [
                    'disk_name' => md5((string) $id) . '.png',
                    'file_name' => 'file-' . $id . '.png',
                    'file_size' => mt_rand(1000, 900000),
                    'content_type' => 'image/png',
                    'field' => $isTarget ? 'avatar' : $fields[mt_rand(0, 11)],
                    'attachment_id' => $isTarget ? (string) $user->id : (string) mt_rand(2, max(2, intdiv($target, 3))),
                    'attachment_type' => $isTarget ? BenchUser::class : $types[mt_rand(0, 39)],
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

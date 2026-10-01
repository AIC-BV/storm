<?php
// Int vs string binding of attachment_id on storm_bench.files, warm and right after a full table scan.
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=storm_bench', 'root', getenv('BENCH_DB_PASSWORD') ?: '');
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$type = 'Winter\Storm\Benchmarks\BenchUser';

$lookup = 'select * from files where attachment_type = ? and attachment_id = ? and field = ? and attachment_id is not null order by sort_order';
$eagerInt = 'select * from files where attachment_id in (' . implode(', ', range(1, 25)) . ') and attachment_type = ? and field = ?';
$eagerStr = 'select * from files where attachment_id in (' . implode(', ', array_map(fn ($i) => "'$i'", range(1, 25))) . ') and attachment_type = ? and field = ?';

$cases = [
    'lookup int' => [$lookup, [[$type, PDO::PARAM_STR], [1, PDO::PARAM_INT], ['avatar', PDO::PARAM_STR]]],
    'lookup string' => [$lookup, [[$type, PDO::PARAM_STR], ['1', PDO::PARAM_STR], ['avatar', PDO::PARAM_STR]]],
    'eager int' => [$eagerInt, [[$type, PDO::PARAM_STR], ['avatar', PDO::PARAM_STR]]],
    'eager string' => [$eagerStr, [[$type, PDO::PARAM_STR], ['avatar', PDO::PARAM_STR]]],
];

$run = function (string $sql, array $params): float {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => [$value, $paramType]) {
        $stmt->bindValue($i + 1, $value, $paramType);
    }
    $start = hrtime(true);
    $stmt->execute();
    $stmt->fetchAll();
    return (hrtime(true) - $start) / 1e6;
};
$scan = fn () => $pdo->query('select count(*) from files where description is not null')->fetchColumn();
$median = function (array $t): float { sort($t); return $t[intdiv(count($t), 2)]; };

$out = [];
foreach ($cases as $name => [$sql, $params]) {
    $run($sql, $params);
    $warm = [];
    for ($i = 0; $i < 15; $i++) {
        $warm[] = $run($sql, $params);
    }
    $cold = [];
    for ($i = 0; $i < 7; $i++) {
        $scan();
        $cold[] = $run($sql, $params);
    }
    $out[$name] = ['warm_ms' => $median($warm), 'after_scan_ms' => $median($cold)];
    printf("%-14s warm %8.2f ms   after a table scan %8.2f ms\n", $name, $out[$name]['warm_ms'], $out[$name]['after_scan_ms']);
}
file_put_contents(__DIR__ . '/cold.json', json_encode(['rows' => (int) $pdo->query('select count(*) from files')->fetchColumn(), 'cases' => $out], JSON_PRETTY_PRINT));

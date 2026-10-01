# Attachment key benchmark

Benchmark for the string-key change in wintercms/storm (`perf/attach-string-parent-key`).

`AttachKeyBenchmark.php` grows a `files` table, which has Winter's `system_files` columns and indexes, from 1k to 1M synthetic rows. At each size it times `$user->avatar()->get()` and an eager load of 25 parents with `with('avatar')`, and records the median time, the `EXPLAIN` plan, the binding types and the result. Storm's per-request query memo (`MemoryCache`) is turned off, and every timed run is checked to reach the database. It needs MySQL, and it drops and recreates its tables in a `storm_bench` database.

```bash
git checkout perf/attach-string-parent-key
STORM=$(pwd) BASE=develop BENCH_DB_PASSWORD=... ./run.sh
```

`cold.php` runs the same two queries over raw PDO on the existing 1M-row table, with an integer and with a string key, both warm and right after a full table scan.

`fixed.json` and `develop.json` are the raw results behind `attach-key-benchmark.png`, from MySQL 8.0.45 and PHP 8.4.24 on Windows.

# withDeferred() benchmark

Benchmark for the `withDeferred()` change in wintercms/storm (`perf/with-deferred-skip-unbound`).

`WithDeferredBenchmark.php` times `$user->avatar()->withDeferred($sessionKey)->get()` while it grows a `files` table, which has Winter's `system_files` columns and indexes, from 1k to 1M synthetic rows. It measures two cases at each size: nothing deferred in the session, and one binding pending. It records the median time, the `EXPLAIN` plan and the returned ids. It needs MySQL, and it drops and recreates its tables in a `storm_bench` database.

```bash
git checkout perf/with-deferred-skip-unbound
STORM=$(pwd) BASE=develop BENCH_DB_PASSWORD=... ./run.sh
```

`fixed.json` and `develop.json` are the raw results behind `withdeferred-benchmark.png`, from MySQL 8.0.45 and PHP 8.4.24 on Windows.

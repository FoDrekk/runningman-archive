"""
php_diagnostics.py — reuse the PHP system's own LOCAL diagnostic state
for comparison.json, rather than inventing PHP numbers (Phase 6's
explicit instruction).

This shells out to the repo's own `php` CLI and calls three read-only
methods that already exist in the PHP codebase:

    RmSourceRegistry::instance()->coveredFields()  — which fields ANY
        configured PHP adapter claims to be able to extract (a
        capability fact, not a live result)
    RmSourceHealth::instance()->all()              — the locally
        recorded health/status of each configured source, from
        whatever real scraping activity has previously run and been
        recorded in this database
    RmMissingData::maxEpisode()                    — the archive's own
        stored maximum episode number (never treated as "latest
        episode" by the PHP system itself either — see
        RmLatestEpisode's own doc comment — reported here only as
        "what the archive currently holds locally")

If PHP is not runnable, or the database is not reachable, this reports
that honestly instead of fabricating a result — exactly Phase 6's
"If exact live PHP comparison is not possible from the current
environment, explicitly state that."
"""
from __future__ import annotations

import json
import os
import subprocess

_REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))

_PHP_SNIPPET = r"""
error_reporting(0);
chdir(%(repo_root)s);
require_once 'includes/scraping/bootstrap.php';
$out = ['ok' => true];
try {
    $db = getDBSafe();
    $out['db_reachable'] = $db !== null;

    $out['covered_fields'] = RmSourceRegistry::instance()->coveredFields();

    $health = RmSourceHealth::instance()->all();
    $usable = 0; $attempted = 0; $blocking = [];
    foreach ($health as $name => $h) {
        if (empty($h['enabled'])) continue;
        $attempted++;
        if (($h['status'] ?? 'unknown') === 'ok') $usable++;
        elseif (($h['status'] ?? 'unknown') !== 'unknown') {
            $blocking[] = "$name: " . $h['status'] . (!empty($h['last_error']) ? ' — ' . $h['last_error'] : '');
        }
    }
    $out['sources_attempted'] = $attempted;
    $out['sources_usable'] = $usable;
    $out['blocking_reasons'] = $blocking;
    $out['sources_never_probed'] = array_values(array_filter(array_keys($health),
        fn($n) => !empty($health[$n]['enabled']) && ($health[$n]['status'] ?? 'unknown') === 'unknown'));

    if ($db !== null) {
        $out['archive_max_episode_stored_locally'] = (new RmMissingData($db))->maxEpisode();
    } else {
        $out['archive_max_episode_stored_locally'] = null;
    }
} catch (Throwable $e) {
    $out = ['ok' => false, 'error' => $e->getMessage()];
}
echo json_encode($out);
"""


def collect_php_diagnostics() -> dict:
    """Best-effort, read-only. Never writes to the PHP database."""
    php_bin = _find_php()
    if php_bin is None:
        return {
            "ok": False,
            "reason": "No `php` executable found on PATH in this environment — cannot reuse PHP-side "
                      "diagnostics. This is stated honestly rather than inventing PHP numbers.",
        }

    snippet = _PHP_SNIPPET % {"repo_root": json.dumps(_REPO_ROOT)}
    try:
        proc = subprocess.run(
            [php_bin, "-r", snippet],
            capture_output=True, text=True, timeout=30, cwd=_REPO_ROOT,
        )
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "reason": f"Failed to invoke PHP: {e}"}

    if proc.returncode != 0:
        return {"ok": False, "reason": f"PHP exited {proc.returncode}: {proc.stderr.strip()[:500]}"}

    try:
        data = json.loads(proc.stdout.strip() or "{}")
    except json.JSONDecodeError:
        return {"ok": False, "reason": f"PHP diagnostic output was not valid JSON: {proc.stdout[:300]!r}"}

    if not data.get("ok", False):
        return {"ok": False, "reason": data.get("error", "Unknown PHP-side error")}
    return {"ok": True, **data}


def _find_php() -> str | None:
    from shutil import which
    return which("php")

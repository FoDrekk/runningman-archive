<?php
// Quick diagnostic — visit /admin/_diagnostic.php directly.
// If THIS blank-pages too, the problem is Apache/PHP config, not our code.
echo "<h2>PHP is executing ✓</h2>";
echo "<p>PHP version: " . phpversion() . "</p>";
try {
    require_once __DIR__ . '/../config/db.php';
    $db = getDB();
    echo "<p style='color:green'>✓ Database connection OK</p>";
    $tables = $db->query("SHOW TABLES LIKE 'sync_state'")->fetchAll();
    echo $tables ? "<p style='color:green'>✓ sync_state table exists</p>" : "<p style='color:red'>✗ sync_state table MISSING — run database/stability_patch.sql</p>";
    $tables2 = $db->query("SHOW TABLES LIKE 'activity_log'")->fetchAll();
    echo $tables2 ? "<p style='color:green'>✓ activity_log table exists</p>" : "<p style='color:red'>✗ activity_log table MISSING — run database/stability_patch.sql</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ DB Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
try {
    require_once __DIR__ . '/../includes/system.php';
    echo "<p style='color:green'>✓ system.php loaded without error</p>";
    var_dump(stabilityTablesExist());
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ system.php error: " . htmlspecialchars($e->getMessage()) . " (line " . $e->getLine() . ")</p>";
}

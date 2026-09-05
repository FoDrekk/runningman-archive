<?php
// ============================================================
// JSON API guard — every admin AJAX endpoint must return valid JSON,
// on success and on failure alike.
//
// THE BUG THIS EXISTS TO PREVENT
// PHP's default web configuration (XAMPP included) has
// display_errors=On and html_errors=On. A single notice-level problem
// anywhere in a handler therefore prints
//
//     <br />\n<b>Warning</b>: Undefined array key "x" in /path.php
//
// BEFORE the JSON body. The browser then reports
// `Unexpected token '<', "<br /><b>"... is not valid JSON`, the UI
// silently stops working, and the server log looks fine. Worse, it is
// environment-dependent: it never reproduces on a dev box with
// display_errors=Off.
//
// THE FIX, IN THREE PARTS
//   1. Buffer everything. Nothing a handler prints reaches the wire
//      except through rmJsonOut().
//   2. Keep diagnostics OUT OF THE BODY BUT NOT OUT OF THE RESPONSE.
//      Warnings are collected and returned inside the JSON as
//      `_warnings`, and stray output as `_stray_output`. They are also
//      written to the PHP error log. Nothing is hidden — the next time
//      this happens, the response names the file and line.
//   3. Survive fatals. A shutdown handler turns an uncaught error,
//      an exhausted memory limit or an execution timeout into a valid
//      JSON error body instead of a half-written page.
// ============================================================

/** Collected during one request; attached to the response by rmJsonOut(). */
$GLOBALS['__rm_json_warnings'] = [];
$GLOBALS['__rm_json_active']   = false;
$GLOBALS['__rm_json_sent']     = false;

/**
 * Call once at the top of an AJAX handler, before doing any work.
 * Safe to call more than once per request.
 */
function rmJsonBegin(): void
{
    if (!empty($GLOBALS['__rm_json_active'])) return;
    $GLOBALS['__rm_json_active'] = true;

    // Capture anything printed, including warnings, so it cannot corrupt
    // the body. It is reported through the JSON instead.
    ob_start();

    // Errors stay LOGGED; they just stop being printed into the body.
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    @ini_set('log_errors', '1');

    set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0): bool {
        // Respect an @-suppressed expression and the configured level.
        if (!(error_reporting() & $no)) return true;
        $label = match ($no) {
            E_WARNING, E_USER_WARNING       => 'Warning',
            E_NOTICE,  E_USER_NOTICE        => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            E_RECOVERABLE_ERROR             => 'Recoverable error',
            default                         => 'Error',
        };
        $entry = sprintf('%s: %s in %s:%d', $label, $msg, $file, $line);
        $GLOBALS['__rm_json_warnings'][] = $entry;
        error_log('[rm-json] ' . $entry);
        return true;   // handled — do not let PHP print it
    });

    register_shutdown_function(function (): void {
        if (empty($GLOBALS['__rm_json_active'])) return;
        if (!empty($GLOBALS['__rm_json_sent'])) return;   // rmJsonOut() already finished the response

        // ── A fatal skips every return path, so build the response here.
        $e = error_get_last();
        if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            error_log(sprintf('[rm-json] FATAL: %s in %s:%d', $e['message'], $e['file'], $e['line']));
            echo json_encode([
                'ok'    => false,
                'error' => 'Server error: ' . $e['message'],
                'where' => basename((string)$e['file']) . ':' . (int)$e['line'],
                'fatal' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        // ── Otherwise: an endpoint that echoed its own JSON and exited
        // without going through rmJsonOut(). Rather than rewrite thirty
        // handler branches, upgrade the response here — so every endpoint
        // reports captured warnings and stray output, not just the ones
        // that were converted.
        $buffered = '';
        while (ob_get_level() > 0) $buffered = ob_get_clean() . $buffered;
        if ($buffered === '') return;

        $decoded = json_decode($buffered, true);
        $isJson  = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
        $extras  = [];
        if (!empty($GLOBALS['__rm_json_warnings'])) $extras['_warnings'] = array_values(array_unique($GLOBALS['__rm_json_warnings']));

        if ($isJson && $extras) {
            // Only associative payloads can carry diagnostics; a list
            // response (e.g. the health check array) is left as it is.
            if (array_is_list($decoded)) {
                echo $buffered;
                return;
            }
            echo json_encode($decoded + $extras, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        // Not JSON at all: either an HTML page that legitimately fell
        // through, or a body already corrupted before the guard could
        // help. Pass a page through untouched; salvage anything else
        // into a valid JSON error naming what leaked.
        if (!$isJson && preg_match('/^\s*(<!DOCTYPE|<html)/i', $buffered)) { echo $buffered; return; }
        if (!$isJson) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            error_log('[rm-json] non-JSON body salvaged: ' . mb_substr($buffered, 0, 500));
            echo json_encode([
                'ok'            => false,
                'error'         => 'Endpoint produced a non-JSON body',
                '_stray_output' => mb_substr(trim($buffered), 0, 2000),
            ] + $extras, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }
        echo $buffered;
    });
}

/**
 * Emit the response and stop. Anything the handler printed by accident
 * is reported as `_stray_output` rather than being silently dropped —
 * a swallowed warning is how this class of bug survives.
 */
function rmJsonOut(array $payload, int $code = 200): never
{
    $stray = '';
    while (ob_get_level() > 0) $stray = ob_get_clean() . $stray;
    $stray = trim($stray);

    if ($stray !== '') {
        $payload['_stray_output'] = mb_substr($stray, 0, 2000);
        error_log('[rm-json] stray output: ' . mb_substr($stray, 0, 500));
    }
    if (!empty($GLOBALS['__rm_json_warnings'])) {
        $payload['_warnings'] = array_values(array_unique($GLOBALS['__rm_json_warnings']));
    }

    $GLOBALS['__rm_json_sent'] = true;
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        // Never leave the client with an unparseable body, whatever the payload did.
        $json = json_encode([
            'ok'    => false,
            'error' => 'Response could not be encoded as JSON: ' . json_last_error_msg(),
        ]);
    }
    echo $json;
    exit;
}

/** Convenience for a handler that failed in a way it understands. */
function rmJsonFail(string $message, int $code = 200, array $extra = []): never
{
    rmJsonOut(['ok' => false, 'error' => $message] + $extra, $code);
}

/**
 * Wrap a handler so a thrown exception becomes a JSON error rather than
 * a stack trace in the body.
 */
function rmJsonHandle(callable $fn): never
{
    rmJsonBegin();
    try {
        $result = $fn();
        rmJsonOut(is_array($result) ? $result : ['ok' => true]);
    } catch (Throwable $e) {
        error_log(sprintf('[rm-json] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        rmJsonOut([
            'ok'    => false,
            'error' => $e->getMessage(),
            'where' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    }
}

/**
 * Refuse to render an HTML page for a request that asked for JSON.
 *
 * Every admin page has the same shape: AJAX handlers, then the page
 * render. If a request arrives with ?a=something and no handler
 * matches — a typo, a stale client, a renamed action, a handler
 * removed in a refactor — control falls through to the page, and the
 * caller gets a full HTML document where it expected JSON. That is
 * exactly the `Unexpected token '<', "<!DOCTYPE"...` failure this
 * codebase has already fixed once, arriving by a different route.
 *
 * Called immediately before each page's layout include, it turns that
 * silent class of bug into a 400 with a readable message.
 */
function rmJsonRejectUnknownAction(?string $action = null): void
{
    $action ??= ($_GET['a'] ?? null);
    if ($action === null || $action === '') return;
    rmJsonOut([
        'ok'    => false,
        'error' => "Unknown action '" . mb_substr((string)$action, 0, 40) . "'",
        'hint'  => 'No handler matched, so this request would otherwise have received an HTML page.',
    ], 400);
}

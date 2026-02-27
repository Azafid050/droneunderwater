<?php
// Allow CORS - Tambahkan ini di PALING ATAS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight request (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

// === KONFIGURASI DATABASE ===
$servername = "localhost"; 
$username   = "underwat_fauzan";
$password   = "qbHekFNGTMxkvdNMLHkq"; 
$dbname     = "underwat_fauzan";

// === KONEKSI DATABASE ===
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]));
}

// ============================================================
// ENDPOINT 1: INSERT DATA DARI DRONE
// Sensor: pH (kualitas_air) + Turbidity (tahan) + Baterai (daya_listrik)
// Contoh: api.php?kualitas_air=7.20&tahan=312.50&daya_listrik=100
// ============================================================
if (isset($_GET['kualitas_air']) && isset($_GET['tahan']) && isset($_GET['daya_listrik'])) {

    $kualitas_air = floatval($_GET['kualitas_air']);
    $tahan        = floatval($_GET['tahan']);
    $daya_listrik = floatval($_GET['daya_listrik']);

    $stmt = $conn->prepare("INSERT INTO drone_logs (kualitas_air, tahan, daya_listrik) VALUES (?, ?, ?)");
    $stmt->bind_param("ddd", $kualitas_air, $tahan, $daya_listrik);

    if ($stmt->execute()) {
        echo json_encode([
            "status"  => "success",
            "message" => "Data inserted successfully",
            "id"      => $conn->insert_id,
            "data"    => [
                "pH"          => $kualitas_air,
                "turbidity"   => $tahan,
                "battery"     => $daya_listrik
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Insert error: " . $stmt->error]);
    }

    $stmt->close();

// ============================================================
// ENDPOINT 2: AMBIL DATA TERBARU (live monitoring)
// Contoh: api.php?get_latest=true
// ============================================================
} elseif (isset($_GET['get_latest'])) {

    $sql    = "SELECT * FROM drone_logs ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        // Pastikan selalu mengembalikan JSON dengan format yang konsisten
        echo json_encode($data);
    } else {
        echo json_encode(["status" => "empty", "message" => "No data found"]);
    }

// ============================================================
// ENDPOINT 3: LAPORAN RINGKASAN (tab Reports)
// Contoh: api.php?get_reports=true&period=daily|weekly|monthly
// ============================================================
} elseif (isset($_GET['get_reports'])) {

    $period = isset($_GET['period']) ? $_GET['period'] : 'daily';

    switch ($period) {
        case 'weekly':  $interval = "7 DAY";  break;
        case 'monthly': $interval = "30 DAY"; break;
        case 'daily':
        default:        $interval = "1 DAY";  break;
    }

    // Ringkasan statistik
    $sqlSummary = "SELECT 
                        AVG(kualitas_air) AS avg_kualitas_air,
                        MIN(kualitas_air) AS min_ph,
                        MAX(kualitas_air) AS max_ph,
                        AVG(tahan)        AS avg_tahan,
                        MAX(tahan)        AS max_tahan,
                        AVG(daya_listrik) AS avg_daya_listrik,
                        COUNT(*)          AS total_logs
                   FROM drone_logs
                   WHERE timestamp >= NOW() - INTERVAL $interval";

    // 10 data terbaru untuk tabel history
    $sqlHistory = "SELECT id, timestamp, kualitas_air, tahan, daya_listrik
                   FROM drone_logs
                   WHERE timestamp >= NOW() - INTERVAL $interval
                   ORDER BY id DESC
                   LIMIT 10";

    $resSummary = $conn->query($sqlSummary);
    $resHistory = $conn->query($sqlHistory);

    $summary = [];
    $history = [];

    if ($resSummary && $resSummary->num_rows > 0) {
        $summary = $resSummary->fetch_assoc();
    }

    if ($resHistory && $resHistory->num_rows > 0) {
        while ($row = $resHistory->fetch_assoc()) {
            $history[] = $row;
        }
    }

    echo json_encode([
        "status"  => "success",
        "period"  => $period,
        "summary" => $summary,
        "history" => $history
    ]);

// ============================================================
// ENDPOINT 4: DATA HISTORIS UNTUK GRAFIK
// Contoh: api.php?get_history=true&limit=20
// ============================================================
} elseif (isset($_GET['get_history'])) {

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    if ($limit > 100) $limit = 100;

    $sql    = "SELECT * FROM drone_logs ORDER BY id DESC LIMIT $limit";
    $result = $conn->query($sql);

    $rows = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $rows = array_reverse($rows);
    }

    echo json_encode(["status" => "success", "data" => $rows]);

// ============================================================
// ENDPOINT 5: CEK KONEKSI DATABASE
// Contoh: api.php?test=true
// ============================================================
} elseif (isset($_GET['test'])) {
    
    echo json_encode([
        "status" => "success",
        "message" => "API is working",
        "database" => "connected",
        "timestamp" => date("Y-m-d H:i:s")
    ]);

// ============================================================
// DEFAULT: Tampilkan daftar endpoint yang tersedia
// ============================================================
} else {
    echo json_encode([
        "status"    => "error",
        "message"   => "Parameter tidak dikenali.",
        "endpoints" => [
            "INSERT data drone" => "api.php?kualitas_air=7.2&tahan=312&daya_listrik=100",
            "GET latest data"   => "api.php?get_latest=true",
            "GET reports"       => "api.php?get_reports=true&period=daily|weekly|monthly",
            "GET history chart" => "api.php?get_history=true&limit=20",
            "TEST connection"    => "api.php?test=true"
        ]
    ]);
}

$conn->close();
?>
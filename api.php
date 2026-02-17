<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Allow cross-origin requests from dashboard
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

// ═══════════════════════════════════════════
// 🌊 SeaPhonk - Underwater Drone API
// ═══════════════════════════════════════════
// Parameter mapping (ESP32 → Database):
//   kualitas_air  → pH Level (0–14)
//   tahan         → Turbidity in NTU (0–1000)
//   udara         → Suhu / Temperature in °C
//   daya_listrik  → Battery / Power Level
// ═══════════════════════════════════════════

// Database Configuration
$servername = "localhost"; 
$username = "underwat_fauzan"; 
$password = "qbHekFNGTMxkvdNMLHkq"; 
$dbname = "underwat_fauzan"; 

// Connect to database
$conn = new mysqli($servername, $username, $password, $dbname);
    
// Check connection
if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]));
}

// Get parameters from URL (GET request from ESP32)
$kualitas_air = isset($_GET['kualitas_air']) ? floatval($_GET['kualitas_air']) : null;  // pH
$tahan = isset($_GET['tahan']) ? floatval($_GET['tahan']) : null;                        // Turbidity (NTU)
$udara = isset($_GET['udara']) ? floatval($_GET['udara']) : null;                        // Suhu (°C)
$daya_listrik = isset($_GET['daya_listrik']) ? floatval($_GET['daya_listrik']) : null;  // Battery

// ── INSERT DATA (from ESP32) ──
if ($kualitas_air !== null && $tahan !== null && $udara !== null && $daya_listrik !== null) {
    
    $stmt = $conn->prepare("INSERT INTO drone_logs (kualitas_air, tahan, udara, daya_listrik) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("dddd", $kualitas_air, $tahan, $udara, $daya_listrik);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success", 
            "message" => "Data inserted successfully",
            "data" => [
                "ph" => $kualitas_air,
                "turbidity_ntu" => $tahan,
                "suhu_celsius" => $udara,
                "battery" => $daya_listrik
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }

    $stmt->close();

// ── GET LATEST DATA (for Dashboard) ──
} elseif (isset($_GET['get_latest'])) {
    $sql = "SELECT * FROM drone_logs ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode($row);
    } else {
        echo json_encode(["status" => "empty", "message" => "No data found. Waiting for drone..."]);
    }

// ── GET HISTORY (for Chart - optional) ──
} elseif (isset($_GET['get_history'])) {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $limit = min($limit, 100); // Max 100 rows
    
    $sql = "SELECT * FROM drone_logs ORDER BY id DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    echo json_encode([
        "status" => "success",
        "count" => count($data),
        "data" => array_reverse($data) // Oldest first for chart
    ]);
    
    $stmt->close();

// ── ERROR: Missing parameters ──
} else {
    echo json_encode([
        "status" => "error", 
        "message" => "Missing parameters. Required: kualitas_air (pH), tahan (NTU), udara (°C), daya_listrik OR get_latest=true OR get_history=true"
    ]);
}

$conn->close();
?>
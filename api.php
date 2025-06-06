<?php
require_once 'config.php';

header('Content-Type: application/json');

// Function to fetch all rooms
function getRooms() {
    global $conn;
    $result = mysqli_query($conn, "SELECT * FROM rooms");
    if (!$result) {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        return;
    }
    $rooms = mysqli_fetch_all($result, MYSQLI_ASSOC);
    echo json_encode($rooms);
}

// Function to create a new room
function createRoom() {
    global $conn;
    $data = json_decode(file_get_contents('php://input'), true);

    $correctPassword = "admin123"; // You can replace this with a config/env variable

    if (!isset($data['password']) || $data['password'] !== $correctPassword) {
        echo json_encode(['status' => 'error', 'message' => 'Incorrect password.']);
        exit();
    }

    if (!isset($data['room_name']) || !isset($data['sensor_ids'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
        return;
    }

    $room_name = htmlspecialchars(strip_tags($data['room_name']));
    $sensor_ids = $data['sensor_ids'];

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "INSERT INTO rooms (room_name) VALUES (?)");
        mysqli_stmt_bind_param($stmt, "s", $room_name);
        mysqli_stmt_execute($stmt);
        $room_id = mysqli_insert_id($conn);

        if (!empty($sensor_ids)) {
            foreach ($sensor_ids as $sensor_id) {
                $stmt = mysqli_prepare($conn, "INSERT INTO sensors (sensor_id, room_id) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmt, "si", $sensor_id, $room_id);
                mysqli_stmt_execute($stmt);
            }
        }

        mysqli_commit($conn);
        echo json_encode(['status' => 'success', 'room_id' => $room_id]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// Function to delete a room and all its related data using room name
// Function to delete a room and all its related data using room name
// Function to delete a room and all its related data using room_id
function deleteRoom() {
    global $conn;
    $data = json_decode(file_get_contents('php://input'), true);

     

    if (!isset($data['room_name']) || empty(trim($data['room_name']))) {
        echo json_encode(['status' => 'error', 'message' => 'Room name is required']);
        exit();
    }

    $room_name = trim($data['room_name']);

    // Get room_id from room_name
    $stmt = mysqli_prepare($conn, "SELECT room_id FROM rooms WHERE room_name = ?");
    mysqli_stmt_bind_param($stmt, "s", $room_name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $room_id);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$room_id) {
        echo json_encode(['status' => 'error', 'message' => 'Room not found']);
        exit();
    }

    // Fetch sensor IDs
    $sensor_ids = [];
    $sensor_stmt = mysqli_prepare($conn, "SELECT sensor_id FROM sensors WHERE room_id = ?");
    mysqli_stmt_bind_param($sensor_stmt, "i", $room_id);
    mysqli_stmt_execute($sensor_stmt);
    mysqli_stmt_bind_result($sensor_stmt, $sensor_id);
    while (mysqli_stmt_fetch($sensor_stmt)) {
        $sensor_ids[] = $sensor_id;
    }
    mysqli_stmt_close($sensor_stmt);

    mysqli_begin_transaction($conn);
    try {
        // Delete all sensor readings
        if (!empty($sensor_ids)) {
            foreach ($sensor_ids as $sid) {
                $del_reading = mysqli_prepare($conn, "DELETE FROM sensor_readings WHERE sensor_id = ?");
                mysqli_stmt_bind_param($del_reading, "i", $sid);
                mysqli_stmt_execute($del_reading);
                mysqli_stmt_close($del_reading);
            }
        }

        // Delete sensors
        $del_sensors = mysqli_prepare($conn, "DELETE FROM sensors WHERE room_id = ?");
        mysqli_stmt_bind_param($del_sensors, "i", $room_id);
        mysqli_stmt_execute($del_sensors);
        mysqli_stmt_close($del_sensors);

        // Delete the room
        $del_room = mysqli_prepare($conn, "DELETE FROM rooms WHERE room_id = ?");
        mysqli_stmt_bind_param($del_room, "i", $room_id);
        mysqli_stmt_execute($del_room);
        mysqli_stmt_close($del_room);

        mysqli_commit($conn);
        echo json_encode(['status' => 'success', 'message' => "Room '$room_name' and its data deleted successfully"]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['status' => 'error', 'message' => 'Error deleting room: ' . $e->getMessage()]);
    }
}







// Function to fetch sensor data for a room
function getRoomData() {
    global $conn;

    if (!isset($_GET['room_id']) || !is_numeric($_GET['room_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Room ID']);
        return;
    }

    $room_id = intval($_GET['room_id']);
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;

    $stmt = mysqli_prepare($conn, "
        SELECT s.sensor_id, sr.temperature, sr.humidity, sr.timestamp
        FROM sensor_readings sr
        JOIN sensors s ON sr.sensor_id = s.sensor_id
        WHERE s.room_id = ?
        ORDER BY sr.timestamp DESC
        LIMIT ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $room_id, $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        return;
    }

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
    echo json_encode($data);
}

// Function to fetch chart data for temperature statistics
function getChartData() {
    global $conn;

    if (!isset($_GET['room_name']) || !isset($_GET['start_date']) || !isset($_GET['end_date'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters (room_name, start_date, end_date)']);
        return;
    }

    $room_name = htmlspecialchars(strip_tags($_GET['room_name']));
    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid date format (YYYY-MM-DD required)']);
        return;
    }

    // Fetch room_id using room_name
    $stmt = mysqli_prepare($conn, "SELECT room_id FROM rooms WHERE LOWER(room_name) = LOWER(?)");
    mysqli_stmt_bind_param($stmt, "s", $room_name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $room_id);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$room_id) {
        echo json_encode(['status' => 'error', 'message' => 'Room not found']);
        return;
    }

    // Fetch chart data for the room
    $stmt = mysqli_prepare($conn, "
        SELECT 
            DATE(sr.timestamp) as date,
            MIN(sr.temperature) as low,
            MAX(sr.temperature) as high,
            AVG(sr.temperature) as avg,
            (SELECT temperature FROM sensor_readings 
             WHERE sensor_id = sr.sensor_id 
             AND DATE(timestamp) = DATE(sr.timestamp) 
             ORDER BY timestamp DESC LIMIT 1) as close
        FROM sensor_readings sr
        JOIN sensors s ON sr.sensor_id = s.sensor_id
        WHERE s.room_id = ? AND sr.timestamp BETWEEN ? AND ?
        GROUP BY DATE(sr.timestamp)
        ORDER BY DATE(sr.timestamp)
    ");
    mysqli_stmt_bind_param($stmt, "iss", $room_id, $start_date, $end_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        return;
    }

    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);

    if (empty($data)) {
        echo json_encode(['status' => 'error', 'message' => 'No data found for this room and date range']);
        return;
    }

    echo json_encode($data);
}



// Route API requests
$action = isset($_GET['action']) ? $_GET['action'] : null;

switch ($action) {
    case 'getRooms':
        getRooms();
        break;
    case 'createRoom':
        createRoom();
        break;
    case 'deleteRoom':
        deleteRoom();
        break;
    case 'getRoomData':
        getRoomData();
        break;
    case 'getChartData':
        getChartData();
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}

mysqli_close($conn);
?>
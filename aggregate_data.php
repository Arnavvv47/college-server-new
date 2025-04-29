<?php
require_once 'config.php';

header('Content-Type: application/json');

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// 1. Aggregate today's sensor data
$sqlAggregate = "
    
    SELECT 
        sensor_id,
        DATE(timestamp) AS date,
        AVG(temperature) AS avg_temperature,
        MIN(temperature) AS min_temperature,
        MAX(temperature) AS max_temperature,
        AVG(humidity) AS avg_humidity,
        MIN(humidity) AS min_humidity,
        MAX(humidity) AS max_humidity
    FROM sensor_readings
    WHERE timestamp >= CURDATE()
    GROUP BY sensor_id, date
";



$result = $conn->query($sqlAggregate);

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {

        // Insert aggregated data into daily_aggregates table
        $sqlInsert = "
            INSERT INTO daily_aggregates (
                sensor_id, date,
                avg_temperature, min_temperature, max_temperature,
                avg_humidity, min_humidity, max_humidity
            ) VALUES (
                '" . $conn->real_escape_string($row['sensor_id']) . "',
                '" . $row['date'] . "',
                " . (is_null($row['avg_temperature']) ? 'NULL' : round($row['avg_temperature'], 2)) . ",
                " . (is_null($row['min_temperature']) ? 'NULL' : round($row['min_temperature'], 2)) . ",
                " . (is_null($row['max_temperature']) ? 'NULL' : round($row['max_temperature'], 2)) . ",
                " . (is_null($row['avg_humidity']) ? 'NULL' : round($row['avg_humidity'], 2)) . ",
                " . (is_null($row['min_humidity']) ? 'NULL' : round($row['min_humidity'], 2)) . ",
                " . (is_null($row['max_humidity']) ? 'NULL' : round($row['max_humidity'], 2)) . "
            )
        ";

        if ($conn->query($sqlInsert) !== TRUE) {
            echo "Error inserting aggregated data: " . $conn->error . "\n";
        }
    }

    // 2. After inserting, delete old detailed sensor data
    $sqlDelete = "DELETE FROM sensor_readings WHERE timestamp < CURDATE()";

    if ($conn->query($sqlDelete) === TRUE) {
        echo "Old detailed sensor data deleted successfully.\n";
    } else {
        echo "Error deleting old sensor data: " . $conn->error . "\n";
    }

} else {
    echo "No new sensor data to aggregate today.\n";
}

$conn->close();
?>

<?php
// File: get_branch_users.php
session_start();
if (!isset($_SESSION['Admin-name'])) {
  header("location: login.php");
  exit();
}

require 'connectDB.php';

// Initialize response
$response = '<option value="0">All Users</option>';

// Check if branch is set
if(isset($_POST['branch'])) {
    $branch = $_POST['branch'];
    
    // If "All Branches" is selected
    if($branch == "0") {
        // Get all users
        $sql = "SELECT username FROM users WHERE add_fingerid=0 ORDER BY username ASC";
        $result = mysqli_stmt_init($conn);
        if (mysqli_stmt_prepare($result, $sql)) {
            mysqli_stmt_execute($result);
            $resultl = mysqli_stmt_get_result($result);
            while ($row = mysqli_fetch_assoc($resultl)){
                $response .= '<option value="'.$row['username'].'">'.$row['username'].'</option>';
            }
        }
    } else {
        // Get users for specific branch
        $sql = "SELECT username FROM users WHERE device_branch = ? AND add_fingerid=0 ORDER BY username ASC";
        
        $result = mysqli_stmt_init($conn);
        if (mysqli_stmt_prepare($result, $sql)) {
            mysqli_stmt_bind_param($result, "s", $branch);
            mysqli_stmt_execute($result);
            $resultl = mysqli_stmt_get_result($result);
            while ($row = mysqli_fetch_assoc($resultl)){
                $response .= '<option value="'.$row['username'].'">'.$row['username'].'</option>';
            }
        }
    }
}

echo $response;
?>
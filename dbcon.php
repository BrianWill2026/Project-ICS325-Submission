<?php
$conn = mysqli_connect("localhost", "icsbinco_indiclex_c_db_user", "ICS325_chinnamma", "icsbinco_indiclex_c_db");
//$conn = mysqli_connect("localhost", "root", "", "indiclex_db");
if (!$conn) {
    die("Connection Failed: " . mysqli_connect_error());
}
?>

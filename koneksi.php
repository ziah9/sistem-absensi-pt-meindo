<?php // tanda pembuka script PHP

$host = "localhost"; // $host = variabel untuk menyimpan nama host database, "localhost" artinya database ada di komputer/server yang sama
$user = "root"; // $user = username database, di sini memakai user default MySQL yaitu root
$pass = ""; // $pass = password database, di sini kosong artinya user root tidak memakai password
$db   = "absen_pt"; // $db = nama database yang akan dipakai, yaitu absen_pt

$conn = mysqli_connect($host, $user, $pass, $db); // mysqli_connect() = fungsi untuk membuat koneksi ke database MySQL dengan parameter host, user, password, dan nama database

if (!$conn) { // if (!$conn) = cek apakah koneksi gagal, tanda ! berarti "tidak" atau "false"
    die("Koneksi database gagal: " . mysqli_connect_error()); // die() = hentikan program langsung, lalu tampilkan pesan error koneksi + detail error dari mysqli_connect_error()
}
?> 
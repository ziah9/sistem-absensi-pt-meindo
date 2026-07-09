<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include "koneksi.php";
date_default_timezone_set("Asia/Jakarta");

// ======================
// PROTEKSI: WAJIB ADMIN
// ======================
if (!isset($_SESSION['admin_login']) || $_SESSION['admin_login'] !== true) {
    header("Location: login_admin.php");
    exit;
}
if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'admin') {
    header("Location: login_admin.php");
    exit;
}

$pesan = "";
$tipe_pesan = "";

// ======================
// HELPER
// ======================
function columnExists(mysqli $conn, string $table, string $column): bool {
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $res = mysqli_query($conn, $sql);
    return $res && mysqli_num_rows($res) > 0;
}

function generateUserId(mysqli $conn): string {
    do {
        $userId = "USR" . date("ymd") . rand(1000, 9999);

        $cek = mysqli_prepare($conn, "SELECT 1 FROM users WHERE user_id = ? LIMIT 1");
        if (!$cek) {
            die("Prepare cek user_id gagal: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($cek, "s", $userId);
        mysqli_stmt_execute($cek);
        $res = mysqli_stmt_get_result($cek);
        $ada = ($res && mysqli_num_rows($res) > 0);
        mysqli_stmt_close($cek);
    } while ($ada);

    return $userId;
}

if (isset($_POST['simpan_karyawan'])) {
    $name              = trim($_POST['name'] ?? '');
    $jenis_kelamin     = trim($_POST['jenis_kelamin'] ?? '');
    $position          = trim($_POST['position'] ?? '');
    $project           = trim($_POST['project'] ?? '');
    $no_hp             = trim($_POST['no_hp'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $tempat_lahir      = trim($_POST['tempat_lahir'] ?? '');
    $tgl_lahir         = trim($_POST['tgl_lahir'] ?? '');
    $alamat            = trim($_POST['alamat'] ?? '');
    $provinsi          = trim($_POST['provinsi'] ?? '');
    $kabupaten         = trim($_POST['kabupaten'] ?? '');
    $kecamatan         = trim($_POST['kecamatan'] ?? '');
    $kelurahan         = trim($_POST['kelurahan'] ?? '');
    $jenis_jam_kerja   = trim($_POST['jenis_jam_kerja'] ?? '');
    $role              = trim($_POST['role'] ?? '');
    $password          = trim($_POST['password'] ?? '');
    $mulai_bekerja     = trim($_POST['mulai_bekerja'] ?? '');
    $akhir_bekerja     = trim($_POST['akhir_bekerja'] ?? '');

    $foto_nama = "";

    if (
        $name === "" ||
        $jenis_kelamin === "" ||
        $jenis_jam_kerja === "" ||
        $role === "" ||
        $password === "" ||
        $mulai_bekerja === ""
    ) {
        $pesan = "Field Name, Jenis Kelamin, Jenis Jam Kerja, Password, Role, dan Mulai Bekerja wajib diisi.";
        $tipe_pesan = "error";
    } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pesan = "Format email tidak valid.";
        $tipe_pesan = "error";
    } elseif ($akhir_bekerja !== "" && strtotime($akhir_bekerja) < strtotime($mulai_bekerja)) {
        $pesan = "Tanggal akhir bekerja tidak boleh lebih kecil dari tanggal mulai bekerja.";
        $tipe_pesan = "error";
    } else {

        $requiredColumns = [
            'user_id',
            'name',
            'password',
            'role',
            'jenis_kelamin',
            'position',
            'project',
            'no_hp',
            'email',
            'tempat_lahir',
            'tgl_lahir',
            'alamat',
            'provinsi',
            'kabupaten',
            'kecamatan',
            'kelurahan',
            'jenis_jam_kerja',
            'mulai_bekerja',
            'akhir_bekerja',
            'foto'
        ];

        foreach ($requiredColumns as $col) {
            if (!columnExists($conn, 'users', $col)) {
                $dbRes = mysqli_query($conn, "SELECT DATABASE() AS db");
                $dbRow = $dbRes ? mysqli_fetch_assoc($dbRes) : ['db' => '(tidak diketahui)'];
                die("Kolom '$col' tidak ditemukan di tabel users pada database aktif: " . htmlspecialchars($dbRow['db']));
            }
        }

        // ======================
        // UPLOAD FOTO
        // ======================
        if ($pesan === "" && isset($_FILES['foto']) && $_FILES['foto']['name'] != '') {
            $folder = "uploads/karyawan/";
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            $namaFile   = $_FILES['foto']['name'];
            $tmpFile    = $_FILES['foto']['tmp_name'];
            $ukuranFile = (int)$_FILES['foto']['size'];
            $ext        = strtolower(pathinfo($namaFile, PATHINFO_EXTENSION));
            $allowed    = ['jpg', 'jpeg', 'png'];

            if (!in_array($ext, $allowed, true)) {
                $pesan = "Format foto harus JPG, JPEG, atau PNG.";
                $tipe_pesan = "error";
            } elseif ($ukuranFile > 300000) {
                $pesan = "Ukuran foto maksimal 300Kb.";
                $tipe_pesan = "error";
            } else {
                $foto_nama = "karyawan_" . time() . "_" . rand(100, 999) . "." . $ext;

                if (!move_uploaded_file($tmpFile, $folder . $foto_nama)) {
                    $pesan = "Upload foto gagal.";
                    $tipe_pesan = "error";
                }
            }
        }

        // ======================
        // SIMPAN DATA
        // ======================
        if ($pesan === "") {
            $user_id = generateUserId($conn);

            if ($akhir_bekerja === "") {
                $akhir_bekerja = null;
            }

            if ($tgl_lahir === "") {
                $tgl_lahir = null;
            }

            $password_simpan = $password;

            $sql = mysqli_prepare($conn, "INSERT INTO users (
                user_id,
                name,
                password,
                role,
                jenis_kelamin,
                position,
                project,
                no_hp,
                email,
                tempat_lahir,
                tgl_lahir,
                alamat,
                provinsi,
                kabupaten,
                kecamatan,
                kelurahan,
                jenis_jam_kerja,
                mulai_bekerja,
                akhir_bekerja,
                foto
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            if (!$sql) {
                die("Prepare insert gagal: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $sql,
                "ssssssssssssssssssss",
                $user_id,
                $name,
                $password_simpan,
                $role,
                $jenis_kelamin,
                $position,
                $project,
                $no_hp,
                $email,
                $tempat_lahir,
                $tgl_lahir,
                $alamat,
                $provinsi,
                $kabupaten,
                $kecamatan,
                $kelurahan,
                $jenis_jam_kerja,
                $mulai_bekerja,
                $akhir_bekerja,
                $foto_nama
            );

            mysqli_stmt_execute($sql);

            if (mysqli_stmt_affected_rows($sql) === 1) {
                mysqli_stmt_close($sql);
                echo "<script>
                    alert('Data karyawan berhasil ditambahkan');
                    window.location='admin.php';
                </script>";
                exit;
            } else {
                $pesan = "Gagal menambah data karyawan.";
                $tipe_pesan = "error";
            }

            mysqli_stmt_close($sql);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Karyawan - PT Meindo</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --meindo-blue:#0b4ea2;
            --meindo-blue-dark:#083b79;
            --meindo-green:#38b24a;
            --page-bg:#edf2f6;
            --card:#ffffff;
            --border:#d9e1ea;
            --text:#30343a;
            --muted:#6f7782;
            --danger:#dc3545;
            --danger-bg:#fdebed;
            --shadow:0 10px 30px rgba(0,0,0,.08);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:'Poppins',sans-serif;
            background:var(--page-bg);
            color:var(--text);
            padding:10px;
        }
        .container{max-width:1080px;margin:0 auto}
        .page-card{
            background:var(--card);
            border-radius:14px;
            box-shadow:var(--shadow);
            overflow:hidden;
            border:1px solid #e5ebf1;
        }
        .page-header{
            padding:18px 28px;
            background:linear-gradient(90deg, var(--meindo-blue), var(--meindo-blue-dark));
            border-bottom:1px solid rgba(255,255,255,.08);
        }
        .page-header h1{
            font-size:28px;
            font-weight:700;
            color:#fff;
        }
        .page-header p{
            margin-top:4px;
            color:rgba(255,255,255,.85);
            font-size:13px;
        }
        .page-body{padding:20px 28px 10px}
        .toolbar{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }
        .toolbar a{text-decoration:none}
        .btn-top{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            padding:10px 14px;
            border-radius:7px;
            font-size:14px;
            font-weight:600;
            transition:.18s ease;
            border:1px solid transparent;
        }
        .btn-top:hover{transform:translateY(-1px)}
        .btn-add{
            background:linear-gradient(90deg, var(--meindo-green), #52c861);
            color:#fff;
        }
        .btn-list{
            background:#fff;
            color:#1f2937;
            border-color:var(--border);
        }
        .divider{
            height:1px;
            background:#e8edf2;
            margin-bottom:16px;
        }
        .alert{
            margin-bottom:18px;
            padding:13px 14px;
            border-radius:8px;
            font-size:14px;
            line-height:1.5;
            border:1px solid #f3c2c7;
            background:var(--danger-bg);
            color:#842029;
        }
        .form-row{
            display:grid;
            grid-template-columns:170px 1fr;
            gap:16px;
            align-items:start;
            margin-bottom:14px;
        }
        .form-label{
            font-size:15px;
            font-weight:600;
            color:#333;
            padding-top:10px;
        }
        .form-input input,
        .form-input select,
        .form-input textarea{
            width:100%;
            border:1px solid var(--border);
            border-radius:7px;
            padding:11px 13px;
            font-family:'Poppins',sans-serif;
            font-size:14px;
            color:#333;
            outline:none;
            background:#fff;
            transition:.18s ease;
        }
        .form-input input[type="file"]{
            padding:8px 10px;
            background:#fff;
        }
        .form-input textarea{
            min-height:82px;
            resize:vertical;
        }
        .form-input input:focus,
        .form-input select:focus,
        .form-input textarea:focus{
            border-color:var(--meindo-blue);
            box-shadow:0 0 0 4px rgba(11,78,162,.10);
        }
        .helper{
            margin-top:6px;
            font-size:12px;
            color:var(--muted);
            line-height:1.5;
        }
        .footer-actions{
            padding:4px 28px 28px;
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }
        .btn{
            border:none;
            cursor:pointer;
            text-decoration:none;
            padding:12px 18px;
            border-radius:8px;
            font-family:'Poppins',sans-serif;
            font-size:14px;
            font-weight:600;
            transition:.18s ease;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
        }
        .btn:hover{transform:translateY(-1px)}
        .btn-save{
            background:linear-gradient(90deg, var(--meindo-blue), #1560c2);
            color:#fff;
        }
        .btn-back{
            background:#fff;
            color:#1f2937;
            border:1px solid var(--border);
        }
        .note{
            margin-top:4px;
            padding:0 28px 24px;
            color:var(--muted);
            font-size:12px;
            line-height:1.6;
        }
        @media (max-width: 768px){
            body{padding:0}
            .page-card{border-radius:0}
            .page-header,.page-body,.footer-actions,.note{
                padding-left:16px;
                padding-right:16px;
            }
            .page-header h1{font-size:22px}
            .form-row{
                grid-template-columns:1fr;
                gap:8px;
            }
            .form-label{padding-top:0}
        }
    </style>
</head>
<body>

<div class="container">
    <div class="page-card">
        <div class="page-header">
            <h1>Tambah Karyawan</h1>
            <p>PT Meindo Elang Indah</p>
        </div>

        <div class="page-body">
            <div class="toolbar">
                <a href="tambah_karyawan.php" class="btn-top btn-add">+ Tambah User</a>
                <a href="admin.php" class="btn-top btn-list">◉ Daftar User</a>
            </div>

            <div class="divider"></div>

            <?php if ($pesan !== ""): ?>
                <div class="alert"><?= htmlspecialchars($pesan) ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <div class="form-row">
                    <div class="form-label">Foto</div>
                    <div class="form-input">
                        <input type="file" name="foto" accept=".jpg,.jpeg,.png">
                        <div class="helper">Maksimal 300Kb, tipe file: JPG, JPEG, PNG</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Name</div>
                    <div class="form-input">
                        <input type="text" name="name" placeholder="Masukkan nama lengkap" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Jenis Kelamin</div>
                    <div class="form-input">
                        <select name="jenis_kelamin">
                            <option value="">-- Pilih Jenis Kelamin --</option>
                            <option value="Laki-Laki" <?= (($_POST['jenis_kelamin'] ?? '') === 'Laki-Laki') ? 'selected' : '' ?>>Laki-Laki</option>
                            <option value="Perempuan" <?= (($_POST['jenis_kelamin'] ?? '') === 'Perempuan') ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Position</div>
                    <div class="form-input">
                        <input type="text" name="position" placeholder="Masukkan position" value="<?= htmlspecialchars($_POST['position'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Project</div>
                    <div class="form-input">
                        <input type="text" name="project" placeholder="Masukkan project" value="<?= htmlspecialchars($_POST['project'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">No. HP</div>
                    <div class="form-input">
                        <input type="text" name="no_hp" placeholder="Masukkan nomor HP" value="<?= htmlspecialchars($_POST['no_hp'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Email</div>
                    <div class="form-input">
                        <input type="email" name="email" placeholder="Masukkan email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Tempat Lahir</div>
                    <div class="form-input">
                        <input type="text" name="tempat_lahir" placeholder="Masukkan tempat lahir" value="<?= htmlspecialchars($_POST['tempat_lahir'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Tgl. Lahir</div>
                    <div class="form-input">
                        <input type="date" name="tgl_lahir" value="<?= htmlspecialchars($_POST['tgl_lahir'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Alamat</div>
                    <div class="form-input">
                        <textarea name="alamat" placeholder="Masukkan alamat lengkap"><?= htmlspecialchars($_POST['alamat'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Provinsi</div>
                    <div class="form-input">
                        <input type="text" name="provinsi" placeholder="Masukkan provinsi" value="<?= htmlspecialchars($_POST['provinsi'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Kabupaten</div>
                    <div class="form-input">
                        <input type="text" name="kabupaten" placeholder="Masukkan kabupaten" value="<?= htmlspecialchars($_POST['kabupaten'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Kecamatan</div>
                    <div class="form-input">
                        <input type="text" name="kecamatan" placeholder="Masukkan kecamatan" value="<?= htmlspecialchars($_POST['kecamatan'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Kelurahan</div>
                    <div class="form-input">
                        <input type="text" name="kelurahan" placeholder="Masukkan kelurahan" value="<?= htmlspecialchars($_POST['kelurahan'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Jenis Jam Kerja</div>
                    <div class="form-input">
                        <select name="jenis_jam_kerja">
                            <option value="">-- Pilih Jenis Jam Kerja --</option>
                            <option value="Overtime" <?= (($_POST['jenis_jam_kerja'] ?? '') === 'Overtime') ? 'selected' : '' ?>>Overtime</option>
                            <option value="Non Overtime" <?= (($_POST['jenis_jam_kerja'] ?? '') === 'Non Overtime') ? 'selected' : '' ?>>Non Overtime</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Role</div>
                    <div class="form-input">
                        <select name="role">
                            <option value="">-- Pilih Role --</option>
                            <option value="karyawan" <?= (($_POST['role'] ?? '') === 'karyawan') ? 'selected' : '' ?>>Karyawan</option>
                            <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Password</div>
                    <div class="form-input">
                        <input type="text" name="password" placeholder="Masukkan password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Mulai Bekerja</div>
                    <div class="form-input">
                        <input type="date" name="mulai_bekerja" value="<?= htmlspecialchars($_POST['mulai_bekerja'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-label">Akhir Bekerja</div>
                    <div class="form-input">
                        <input type="date" name="akhir_bekerja" value="<?= htmlspecialchars($_POST['akhir_bekerja'] ?? '') ?>">
                        <div class="helper">Kosongkan jika masih aktif bekerja.</div>
                    </div>
                </div>

                <div class="footer-actions">
                    <button type="submit" name="simpan_karyawan" class="btn btn-save">Simpan Data</button>
                    <a href="admin.php" class="btn btn-back">Kembali</a>
                </div>

                <div class="note">
                    Pastikan seluruh data karyawan sudah benar sebelum disimpan ke sistem PT Meindo Elang Indah.
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
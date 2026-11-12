<?php

class LeaderController
{
  private $conn;

  public function __construct($dbConnection)
  {
    $this->conn = $dbConnection;
  }

  /**
   * Private helper to return standardized JSON response
   */
  private function response($statusCode, $statusText, $message, $data = null)
  {
    http_response_code($statusCode);
    $payload = [
      "status" => $statusText,
      "message" => $message
    ];

    if ($data !== null) {
      $payload['data'] = $data;
    }

    echo json_encode($payload);
    exit();
  }


  // synchronize event data
  /**
   * Helper method to synchronize event statuses in the database based on current server time
   */
  private function syncEventStatuses()
  {
    try {
      // 1. Mark events as 'selesai' if current time has passed the end time
      $sqlSelesai = "
                UPDATE events 
                SET status = 'selesai' 
                WHERE status != 'selesai' 
                  AND waktu_selesai <= NOW()
            ";
      $this->conn->exec($sqlSelesai);

      // 2. Mark events as 'live' if current time is within event duration and status is 'mendatang'
      $sqlLive = "
                UPDATE events 
                SET status = 'live' 
                WHERE status = 'mendatang' 
                  AND NOW() BETWEEN waktu_event AND waktu_selesai
            ";
      $this->conn->exec($sqlLive);
    } catch (Exception $e) {
      // Log error silently so it won't break the main request
      error_log("Failed to sync event statuses: " . $e->getMessage());
    }
  }

  // CRUD USER
  // create user: create account
  /**
   * Create a new pilgrim (Jamaah) user account
   * 
   * @param array $requestData
   * @return void
   */
  // CREATE USER FOR LEADER
  /**
   * Create new basic user account assigned strictly to Leader's zone
   * 
   * @param array $requestData
   * @return void
   */
  public function createUser($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $nama = trim($requestData['nama_lengkap'] ?? '');
    $porsi = trim($requestData['nomor_porsi'] ?? '');
    $wa = trim($requestData['whatsapp'] ?? '');
    $password = $requestData['password'] ?? '';
    $role = $requestData['role'] ?? '';

    $leader_id = $requestData['leader_id'] ?? '';
    $zone = $requestData['zone'] ?? '';

    if (empty($nama) || empty($porsi) || empty($wa)) {
      return $this->response(400, "error", "All fields (fullName/nama_lengkap, portionNumber/nomor_porsi, whatsapp) are required.");
    }

    try {
      // 2. FETCH LEADER ZONE IF NOT PROVIDED
      if (empty($zone)) {
        if (empty($leader_id)) {
          return $this->response(400, "error", "ID Ketua atau Zona diperlukan untuk memverifikasi akses Anda. Silakan lengkapi data terlebih dahulu.");
        }

        $queryZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryZone);
        $stmtZone->execute([':leader_id' => $leader_id]);
        $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "error", "Data ketua atau zona tugas tidak ditemukan.");
        }

        $zone = $leader['zona'];
      }

      // 3. CHECK FOR EXISTING PORTION NUMBER OR WHATSAPP
      $checkStmt = $this->conn->prepare("SELECT id FROM users WHERE nomor_porsi = ? OR whatsapp = ? LIMIT 1");
      $checkStmt->execute([$porsi, $wa]);

      if ($checkStmt->rowCount() > 0) {
        return $this->response(409, "error", "Nomor porsi atau nomor WhatsApp sudah terdaftar!");
      }

      // 4. HASH PASSWORD
      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

      // 5. INSERT NEW USER RECORD (Kunci zona dengan zona milik Leader)
      $insertSql = "
        INSERT INTO users (nama_lengkap, nomor_porsi, whatsapp, password, role, zona, is_completed) 
        VALUES (:nama, :porsi, :wa, :password, :role, :zona, 0)
      ";
      $stmt = $this->conn->prepare($insertSql);
      $stmt->execute([
        ':nama' => $nama,
        ':porsi' => $porsi,
        ':wa' => $wa,
        ':password' => $hashedPassword,
        ':role' => $role,
        ':zona' => $zone
      ]);

      $newUserId = (int) $this->conn->lastInsertId();

      // 6. INISIALISASI BARIS TABEL KEBERANGKATAN
      $initKeberangkatan = "INSERT INTO keberangkatan (user_id) VALUES (:user_id)";
      $stmtK = $this->conn->prepare($initKeberangkatan);
      $stmtK->execute([':user_id' => $newUserId]);

      // 7. RETURN STANDARDIZED RESPONSE
      $responseData = [
        "user_id" => $newUserId
      ];

      return $this->response(201, "success", "Jamaah account created successfully!", $responseData);

    } catch (Exception $e) {
      return $this->response(500, "error", "Database Error: " . $e->getMessage());
    }
  }


  // get users
  /**
   * Fetch user list filtered by leader's zone
   *
   * @param array $requestData
   * @return void
   */
  public function getUsers($requestData)
  {
    try {
      // STEP 1: VALIDATE REQUIRED PARAMETERS
      $leaderId = $requestData['leader_id'] ?? null;
      $zone = $requestData['zone'] ?? null;

      // STEP 2: IF ZONE IS NOT PROVIDED, FETCH ZONE FROM LEADER'S PROFILE
      if (empty($zone)) {
        if (empty($leaderId)) {
          return $this->response(400, "error", "Leader ID or Zone is required.");
        }

        $queryGetZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryGetZone);
        $stmtZone->bindParam(':leader_id', $leaderId);
        $stmtZone->execute();
        $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "error", "Leader data or assigned zone not found.");
        }

        $zone = $leader['zona'];
      }

      // STEP 3: FETCH USERS FILTERED BY LEADER'S ZONE
      $querySql = "
            SELECT 
                u.id,
                u.nama_lengkap AS fullName,
                u.gambar AS photoUrl,
                u.role,
                u.nomor_porsi AS portionNumber,
                u.zona AS zone,
                k.status_porsi AS statusPortion,
                k.gform AS statusGoogleForm,
                k.foto AS statusPhoto,
                k.spph AS statusSpph,
                k.mutasi AS statusMutasi,
                k.bio_metrik AS statusBiometrik,
                k.puskesmas AS statusPuskesmas,
                k.mcu AS statusMCU,
                k.pelunasan AS statusPelunasan,
                k.status_passport AS statusPassport,
                k.status_visa AS statusVisa
            FROM users u
            LEFT JOIN keberangkatan k ON u.id = k.user_id
            WHERE u.zona = :zone
        ";

      $stmt_users = $this->conn->prepare($querySql);
      $stmt_users->bindParam(':zone', $zone);
      $stmt_users->execute();
      $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

      // STEP 4: RETURN RESPONSE
      return $this->response(200, "success", "Successfully retrieved users for zone: " . $zone, $users);

    } catch (Exception $e) {
      return $this->response(500, "error", "Internal server error: " . $e->getMessage());
    }
  }


  // get user detail
  /**
   * Fetch detailed user profile and departure status by user ID
   * 
   * @param array $requestData
   * @return void
   */
  public function getUserDetail($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $user_id = $requestData['user_id'] ?? '';
    $leader_id = $requestData['leader_id'] ?? '';
    $zone = $requestData['zone'] ?? '';

    if (empty($user_id)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    try {
      // 2. FETCH LEADER ZONE IF NOT PROVIDED FROM FRONTEND
      if (empty($zone)) {
        if (empty($leader_id)) {
          return $this->response(400, "failed", "Leader ID or Zone is required for authorization.");
        }

        $queryGetZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryGetZone);
        $stmtZone->bindParam(':leader_id', $leader_id);
        $stmtZone->execute();
        $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "failed", "Leader data or assigned zone not found.");
        }

        $zone = $leader['zona'];
      }

      // 3. FETCH USER AND DEPARTURE DETAILS WITH ZONE VERIFICATION
      $sql = "
                SELECT 
                    u.id, u.nama_lengkap, u.nomor_porsi, u.whatsapp, u.role, 
                    u.address, u.birthDate, u.birthPlace, u.companion, u.nama_mahram, u.education, u.experience, 
                    u.fatherName, u.gender, u.healthCondition, u.is_completed, u.job, 
                    u.positiveTrait, u.program, u.skill, u.subDistrict, u.zona, u.village, 
                    u.referensi_nama, u.referensi_wa, u.referensi_asal, u.gambar,
                    
                    k.status_jamaah, k.plot, k.kloter, k.rombongan, k.regu, 
                    k.posisi_porsi, k.status_porsi, k.status_passport, k.status_visa,
                    k.passport, k.visa, k.mutasi, k.bio_metrik, k.puskesmas, 
                    k.mcu, k.pelunasan, k.gform, k.foto AS foto_berkas, k.spph
                FROM users u
                LEFT JOIN keberangkatan k ON u.id = k.user_id
                WHERE u.id = :user_id AND u.zona = :zone
            ";

      $stmt = $this->conn->prepare($sql);
      $stmt->execute([
        ':user_id' => $user_id,
        ':zone' => $zone
      ]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      // 4. CHECK USER EXISTENCE AND MAP DATA
      if (!$user) {
        return $this->response(404, "failed", "User not found or does not belong to your zone.");
      }

      $formattedUser = [
        // main profile & bio data
        "id" => $user['id'],
        "fullName" => $user['nama_lengkap'],
        "portionNumber" => $user['nomor_porsi'],
        "whatsapp" => $user['whatsapp'],
        "role" => $user['role'],
        "address" => $user['address'],
        "birthDate" => $user['birthDate'],
        "birthPlace" => $user['birthPlace'],
        "companion" => $user['companion'],
        "mahramName" => $user['nama_mahram'],
        "education" => $user['education'],
        "experience" => $user['experience'],
        "fatherName" => $user['fatherName'],
        "gender" => $user['gender'],
        "health" => $user['healthCondition'],
        "isCompleted" => (bool) $user['is_completed'],
        "job" => $user['job'],
        "contribution" => $user['positiveTrait'],
        "departure" => $user['program'],
        "expertise" => $user['skill'],
        "subDistrict" => $user['subDistrict'],
        "zone" => $user['zona'],
        "village" => $user['village'],
        "referenceName" => $user['referensi_nama'],
        "referencePhone" => $user['referensi_wa'],
        "referenceOrigin" => $user['referensi_asal'],
        "photoUrl" => $user['gambar'],

        // departure & document details (fallback defaults)
        "pilgrimStatus" => $user['status_jamaah'] ?? 'aktif',
        "plotNumber" => $user['plot'] ?? '',
        "batch" => $user['kloter'] ?? '',
        "group" => $user['rombongan'] ?? '',
        "team" => $user['regu'] ?? '',
        "currPortionPosition" => $user['posisi_porsi'] ?? '',
        "currPortionStatus" => $user['status_porsi'] ?? '',
        "passport" => $user['passport'] ?? '',
        "statusPassport" => $user['status_passport'] ?? '',
        "visa" => $user['visa'] ?? '',
        "statusVisa" => $user['status_visa'] ?? '',

        // document completion status
        "mutationStatus" => $user['mutasi'] ?? 'menunggu',
        "biometricStatus" => $user['bio_metrik'] ?? 'menunggu',
        "puskesmasStatus" => $user['puskesmas'] ?? 'menunggu',
        "mcuStatus" => $user['mcu'] ?? 'menunggu',
        "paymentStatus" => $user['pelunasan'] ?? 'menunggu',
        "googleFormStatus" => $user['gform'] ?? 'menunggu',
        "photoStatus" => $user['foto_berkas'] ?? 'menunggu',
        "spphStatus" => $user['spph'] ?? 'menunggu'
      ];

      // 5. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "User details retrieved successfully.", $formattedUser);

    } catch (Exception $e) {
      return $this->response(500, "error", "Database Error: " . $e->getMessage());
    }
  }

  // helper for update user profile: create an account
  /**
   * Determine geographical zone based on sub-district name
   * 
   * @param string $subDistrict
   * @return string|null
   */
  private function determineZone($subDistrict)
  {
    // normalize string for case-insensitive comparison
    $subDistrict = strtoupper(trim($subDistrict));

    $zones = [
      'A' => ['CIKAMPEK', 'JATISARI', 'KOTABARU', 'PURWASARI', 'TIRTAMULYA'],
      'B' => ['BANYUSARI', 'CILAMAYA KULON', 'CILAMAYA WETAN', 'LEMAHABANG', 'MAJALAYA', 'RAWAMERTA', 'TELAGASARI', 'TEMPURAN'],
      'C' => ['CIAMPEL', 'KARAWANG TIMUR', 'KLARI'],
      'D' => ['KARAWANG BARAT', 'TELUKJAMBE BARAT', 'TELUKJAMBE TIMUR'],
      'E' => ['PANGKALAN', 'TEGALWARU'],
      'F' => ['BATUJAYA', 'CIBUAYA', 'CILEBAR', 'JAYAKERTA', 'KUTAWALUYA', 'PAKISJAYA', 'PEDES', 'RENGASDENGKLOK', 'TIRTAJAYA']
    ];

    foreach ($zones as $zone => $districts) {
      if (in_array($subDistrict, $districts, true)) {
        return $zone;
      }
    }

    return null;
  }


  // udpate user profile: create an account
  /**
   * Update user profile data strictly restricted to Leader's zone
   * 
   * @param array $requestData
   * @return void
   */
  public function updateUserProfile($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';
    $leaderId = $requestData['leader_id'] ?? '';
    $zone = $requestData['zone'] ?? '';

    if (empty($userId)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    // extract scalar fields
    $address = $requestData['address'] ?? '';
    $birthDate = $requestData['birthDate'] ?? '';
    $birthPlace = $requestData['birthPlace'] ?? '';
    $companion = $requestData['companion'] ?? '';
    $mahramName = $requestData['mahramName'] ?? '';
    $education = $requestData['education'] ?? '';
    $experience = $requestData['experience'] ?? '';
    $fatherName = $requestData['fatherName'] ?? '';
    $gender = $requestData['gender'] ?? '';
    $isCompleted = !empty($requestData['isCompleted']) ? 1 : 0;
    $job = $requestData['job'] ?? '';
    $program = $requestData['departure'] ?? '';
    $subDistrict = $requestData['subDistrict'] ?? '';
    $village = $requestData['village'] ?? '';

    $referenceName = $requestData['referenceName'] ?? $requestData['referensi_nama'] ?? '';
    $referencePhone = $requestData['referencePhone'] ?? $requestData['referensi_wa'] ?? '';
    $referenceOrigin = $requestData['referenceOrigin'] ?? $requestData['referensi_asal'] ?? '';

    // format array payloads into comma-separated strings
    $healthCondition = is_array($requestData['health'] ?? null) ? implode(", ", $requestData['health']) : '';
    $positiveTrait = is_array($requestData['contribution'] ?? null) ? implode(", ", $requestData['contribution']) : '';
    $skill = is_array($requestData['expertise'] ?? null) ? implode(", ", $requestData['expertise']) : '';

    // extract profile image URL if present
    $photoUrl = $requestData['profileImage'] ?? $requestData['gambar'] ?? null;

    try {
      // 2. FETCH LEADER ZONE IF NOT PROVIDED FROM FRONTEND
      if (empty($zone)) {
        if (empty($leaderId)) {
          return $this->response(400, "failed", "Leader ID or Zone is required for authorization.");
        }

        $queryZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryZone);
        $stmtZone->execute([':leader_id' => $leaderId]);
        $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "failed", "Leader data or assigned zone not found.");
        }

        $zone = $leader['zona'];
      }

      // 3. VERIFY TARGET USER BELONGS TO LEADER'S ZONE
      $verifyUserStmt = $this->conn->prepare("SELECT id FROM users WHERE id = :user_id AND zona = :zone LIMIT 1");
      $verifyUserStmt->execute([':user_id' => $userId, ':zone' => $zone]);

      if (!$verifyUserStmt->fetch()) {
        return $this->response(403, "failed", "Unauthorized: Target user does not belong to your assigned zone.");
      }

      // 4. BUILD DYNAMIC SQL QUERY & EXECUTE WITH ZONE FILTER GUARD
      $params = [
        $address,
        $birthDate,
        $birthPlace,
        $companion,
        $mahramName,
        $education,
        $experience,
        $fatherName,
        $gender,
        $healthCondition,
        $isCompleted,
        $job,
        $positiveTrait,
        $program,
        $skill,
        $subDistrict,
        $zone,
        $village,
        $referenceName,
        $referencePhone,
        $referenceOrigin
      ];

      if ($photoUrl !== null) {
        $sql = "
                    UPDATE users SET 
                        address=?, birthDate=?, birthPlace=?, companion=?, nama_mahram=?, education=?, 
                        experience=?, fatherName=?, gender=?, healthCondition=?, is_completed=?, 
                        job=?, positiveTrait=?, program=?, skill=?, subDistrict=?, zona=?, village=?, 
                        referensi_nama=?, referensi_wa=?, referensi_asal=?, gambar=? 
                    WHERE id=? AND zona=?
                ";
        $params[] = $photoUrl;
      } else {
        $sql = "
                    UPDATE users SET 
                        address=?, birthDate=?, birthPlace=?, companion=?, nama_mahram=?, education=?, 
                        experience=?, fatherName=?, gender=?, healthCondition=?, is_completed=?, 
                        job=?, positiveTrait=?, program=?, skill=?, subDistrict=?, zona=?, village=?, 
                        referensi_nama=?, referensi_wa=?, referensi_asal=? 
                    WHERE id=? AND zona=?
                ";
      }

      $params[] = $userId;
      $params[] = $zone;

      $stmt = $this->conn->prepare($sql);
      $stmt->execute($params);

      // 5. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Profile updated successfully!");

    } catch (Exception $e) {
      return $this->response(500, "failed", "Failed to update data: " . $e->getMessage());
    }
  }


  // helper for edit user
  /**
   * Convert array or string payload values to comma-separated string
   * 
   * @param mixed $val
   * @return string
   */
  private function toCsv($val)
  {
    if (is_array($val)) {
      return implode(", ", $val);
    }
    if (is_string($val)) {
      return trim($val);
    }
    return '';
  }

  // edit user: pure update user profile
  /**
   * Edit user profile and departure document data restricted to Leader's zone
   * 
   * @param array $requestData
   * @return void
   */
  public function editUsers($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';
    $leaderId = $requestData['leader_id'] ?? '';
    $leaderZone = $requestData['zone_leader'] ?? $requestData['zone'] ?? '';

    if (empty($userId)) {
      return $this->response(400, "failed", "User ID is required.");
    }

    try {
      // 2. FETCH LEADER ZONE IF NOT PROVIDED FROM FRONTEND
      if (empty($leaderZone)) {
        if (empty($leaderId)) {
          return $this->response(400, "failed", "Leader ID or Zone is required for authorization.");
        }

        $queryZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryZone);
        $stmtZone->execute([':leader_id' => $leaderId]);
        $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "failed", "Leader data or assigned zone not found.");
        }

        $leaderZone = $leader['zona'];
      }

      // 3. VERIFY TARGET USER BELONGS TO LEADER'S ZONE
      $verifyStmt = $this->conn->prepare("SELECT id FROM users WHERE id = :user_id AND zona = :zone LIMIT 1");
      $verifyStmt->execute([
        ':user_id' => $userId,
        ':zone' => $leaderZone
      ]);

      if (!$verifyStmt->fetch()) {
        return $this->response(403, "failed", "Unauthorized: Target user does not belong to your assigned zone.");
      }

      // 4. BEGIN TRANSACTION TO ENSURE DATA CONSISTENCY
      $this->conn->beginTransaction();

      // ----------------------------------------------------
      // SECTION A: UPDATE USERS TABLE PROFILE DATA
      // ----------------------------------------------------
      $profileFields = [
        'fullName',
        'portionNumber',
        'whatsapp',
        'fatherName',
        'birthDate',
        'birthPlace',
        'address',
        'subDistrict',
        'village',
        'gender',
        'education',
        'job',
        'experience',
        'departure',
        'companion',
        'mahramName',
        'health',
        'contribution',
        'expertise',
        'referenceName',
        'referencePhone',
        'referenceOrigin',
        'profileImage',
        'isCompleted'
      ];

      $touchesProfile = false;
      foreach ($profileFields as $field) {
        if (array_key_exists($field, $requestData)) {
          $touchesProfile = true;
          break;
        }
      }

      if ($touchesProfile) {
        // lock zone strictly to leader's assigned zone
        $requestData['zona'] = $leaderZone;

        // validate unique portion number if present
        if (array_key_exists('portionNumber', $requestData) && !empty($requestData['portionNumber'])) {
          $checkStmt = $this->conn->prepare("SELECT id FROM users WHERE nomor_porsi = ? AND id != ?");
          $checkStmt->execute([$requestData['portionNumber'], $userId]);
          if ($checkStmt->rowCount() > 0) {
            throw new Exception("Portion number is already used by another user.");
          }
        }

        $columnMap = [
          'fullName' => 'nama_lengkap',
          'portionNumber' => 'nomor_porsi',
          'whatsapp' => 'whatsapp',
          'address' => 'address',
          'birthDate' => 'birthDate',
          'birthPlace' => 'birthPlace',
          'companion' => 'companion',
          'mahramName' => 'nama_mahram',
          'education' => 'education',
          'experience' => 'experience',
          'fatherName' => 'fatherName',
          'gender' => 'gender',
          'isCompleted' => 'is_completed',
          'job' => 'job',
          'departure' => 'program',
          'subDistrict' => 'subDistrict',
          'zona' => 'zona',
          'village' => 'village',
          'referenceName' => 'referensi_nama',
          'referencePhone' => 'referensi_wa',
          'referenceOrigin' => 'referensi_asal',
          'profileImage' => 'gambar',
        ];

        $csvMap = [
          'health' => 'healthCondition',
          'contribution' => 'positiveTrait',
          'expertise' => 'skill'
        ];

        $sets = [];
        $params = [];

        foreach ($columnMap as $field => $column) {
          if (!array_key_exists($field, $requestData)) {
            continue;
          }
          $value = $requestData[$field];
          if ($field === 'isCompleted') {
            $value = !empty($value) ? 1 : 0;
          }
          $sets[] = "$column = ?";
          $params[] = $value;
        }

        foreach ($csvMap as $field => $column) {
          if (!array_key_exists($field, $requestData)) {
            continue;
          }
          $sets[] = "$column = ?";
          $params[] = $this->toCsv($requestData[$field]);
        }

        if (!empty($sets)) {
          $params[] = $userId;
          $params[] = $leaderZone;
          $updateUserSql = "UPDATE users SET " . implode(", ", $sets) . " WHERE id = ? AND zona = ?";
          $stmtUser = $this->conn->prepare($updateUserSql);
          $stmtUser->execute($params);
        }
      }

      // ----------------------------------------------------
      // SECTION B: UPDATE KEBERANGKATAN TABLE DEPARTURE DATA
      // ----------------------------------------------------
      $docFields = [
        'pilgrimStatus',
        'plotNumber',
        'batch',
        'group',
        'team',
        'currPortionPosition',
        'currPortionStatus',
        'passport',
        'statusPassport',
        'visa',
        'statusVisa',
        'mutationStatus',
        'biometricStatus',
        'puskesmasStatus',
        'mcuStatus',
        'paymentStatus',
        'googleFormStatus',
        'photoStatus',
        'spphStatus'
      ];

      $touchesDok = false;
      foreach ($docFields as $field) {
        if (array_key_exists($field, $requestData)) {
          $touchesDok = true;
          break;
        }
      }

      if ($touchesDok) {
        $docColumnMap = [
          'pilgrimStatus' => 'status_jamaah',
          'plotNumber' => 'plot',
          'batch' => 'kloter',
          'group' => 'rombongan',
          'team' => 'regu',
          'currPortionPosition' => 'posisi_porsi',
          'currPortionStatus' => 'status_porsi',
          'passport' => 'passport',
          'statusPassport' => 'status_passport',
          'visa' => 'visa',
          'statusVisa' => 'status_visa',
          'mutationStatus' => 'mutasi',
          'biometricStatus' => 'bio_metrik',
          'puskesmasStatus' => 'puskesmas',
          'mcuStatus' => 'mcu',
          'paymentStatus' => 'pelunasan',
          'googleFormStatus' => 'gform',
          'photoStatus' => 'foto',
          'spphStatus' => 'spph',
        ];

        $checkDok = $this->conn->prepare("SELECT id FROM keberangkatan WHERE user_id = ?");
        $checkDok->execute([$userId]);
        $existingDok = $checkDok->fetch(PDO::FETCH_ASSOC);

        if ($existingDok) {
          // partial update for sent fields
          $sets = [];
          $params = [];
          foreach ($docColumnMap as $field => $column) {
            if (!array_key_exists($field, $requestData)) {
              continue;
            }
            $sets[] = "$column = ?";
            $params[] = $requestData[$field];
          }
          if (!empty($sets)) {
            $params[] = $userId;
            $updateDokSql = "UPDATE keberangkatan SET " . implode(", ", $sets) . " WHERE user_id = ?";
            $stmtDok = $this->conn->prepare($updateDokSql);
            $stmtDok->execute($params);
          }
        } else {
          // full insert with safe enum fallbacks
          $statusJamaah = $requestData['pilgrimStatus'] ?? 'aktif';
          $plot = $requestData['plotNumber'] ?? '';
          $kloter = $requestData['batch'] ?? '';
          $rombongan = $requestData['group'] ?? '';
          $regu = $requestData['team'] ?? '';
          $posisiPorsi = $requestData['currPortionPosition'] ?? '';
          $statusPorsi = $requestData['currPortionStatus'] ?? '';
          $passport = $requestData['passport'] ?? '';
          $statusPassport = $requestData['statusPassport'] ?? 'menunggu';
          $visa = $requestData['visa'] ?? '';
          $statusVisa = $requestData['statusVisa'] ?? 'menunggu';
          $mutasi = $requestData['mutationStatus'] ?? 'menunggu';
          $bioMetrik = $requestData['biometricStatus'] ?? 'menunggu';
          $puskesmas = $requestData['puskesmasStatus'] ?? 'menunggu';
          $mcu = $requestData['mcuStatus'] ?? 'menunggu';
          $pelunasan = $requestData['paymentStatus'] ?? 'menunggu';
          $gform = $requestData['googleFormStatus'] ?? 'menunggu';
          $foto = $requestData['photoStatus'] ?? 'menunggu';
          $spph = $requestData['spphStatus'] ?? 'menunggu';

          $insertDokSql = `
      INSERT INTO keberangkatan (
          user_id, status_jamaah, plot, kloter, rombongan, regu,
          posisi_porsi, status_porsi, passport, status_passport, visa, status_visa, mutasi, bio_metrik, puskesmas,
          mcu, pelunasan, gform, foto, spph
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `;

          $stmtInsertDok = $this->conn->prepare($insertDokSql);
          $stmtInsertDok->execute([
            $userId,
            $statusJamaah,
            $plot,
            $kloter,
            $rombongan,
            $regu,
            $posisiPorsi,
            $statusPorsi,
            $passport,
            $statusPassport,
            $visa,
            $statusVisa,
            $mutasi,
            $bioMetrik,
            $puskesmas,
            $mcu,
            $pelunasan,
            $gform,
            $foto,
            $spph
          ]);
        }
      }

      // 5. COMMIT TRANSACTION
      $this->conn->commit();

      // 6. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Jamaah data updated successfully.");

    } catch (Exception $e) {
      // rollback transaction on failure
      if ($this->conn->inTransaction()) {
        $this->conn->rollBack();
      }

      return $this->response(400, "failed", $e->getMessage());
    }
  }

  // update user documents
  /**
   * Update departure document data strictly restricted to Leader's zone
   * 
   * @param array $requestData
   * @return void
   */
  public function updateDocument($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';
    $leaderId = $requestData['leader_id'] ?? '';
    $zone = $requestData['zone'] ?? '';

    if (empty($userId)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    // extract text & varchar fields
    $statusJamaah = $requestData['pilgrimStatus'] ?? 'aktif';
    $plot = $requestData['plotNumber'] ?? '';
    $kloter = $requestData['batch'] ?? '';
    $rombongan = $requestData['group'] ?? '';
    $regu = $requestData['team'] ?? '';
    $posisiPorsi = $requestData['currPortionPosition'] ?? '';
    $statusPorsi = $requestData['currPortionStatus'] ?? '';
    $passport = $requestData['passport'] ?? '';
    $statusPassport = $requestData['statusPassport'] ?? 'menunggu';
    $visa = $requestData['visa'] ?? '';
    $statusVisa = $requestData['statusVisa'] ?? 'menunggu';

    // extract enum status fields (default: 'menunggu')
    $mutasi = $requestData['mutationStatus'] ?? 'menunggu';
    $bioMetrik = $requestData['biometricStatus'] ?? 'menunggu';
    $puskesmas = $requestData['puskesmasStatus'] ?? 'menunggu';
    $mcu = $requestData['mcuStatus'] ?? 'menunggu';
    $pelunasan = $requestData['paymentStatus'] ?? 'menunggu';
    $gform = $requestData['googleFormStatus'] ?? 'menunggu';
    $foto = $requestData['photoStatus'] ?? 'menunggu';
    $spph = $requestData['spphStatus'] ?? 'menunggu';

    // extract profile completion status if provided
    $isCompleted = isset($requestData['isCompleted']) ? (int) $requestData['isCompleted'] : null;

    try {
      // 2. FETCH LEADER ZONE IF NOT PROVIDED FROM FRONTEND
      if (empty($zone)) {
        if (empty($leaderId)) {
          return $this->response(400, "failed", "Leader ID or Zone is required for authorization.");
        }

        $queryZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryZone);
        $stmtZone->execute([':leader_id' => $leaderId]);
        $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "failed", "Leader data or assigned zone not found.");
        }

        $zone = $leader['zona'];
      }

      // 3. VERIFY TARGET USER BELONGS TO LEADER'S ZONE
      $verifyUserStmt = $this->conn->prepare("SELECT id FROM users WHERE id = :user_id AND zona = :zone LIMIT 1");
      $verifyUserStmt->execute([':user_id' => $userId, ':zone' => $zone]);

      if (!$verifyUserStmt->fetch()) {
        return $this->response(403, "failed", "Unauthorized: Target user does not belong to your assigned zone.");
      }

      // 4. BEGIN TRANSACTION TO MAINTAIN DUAL-TABLE CONSISTENCY
      $this->conn->beginTransaction();

      // check if record exists in keberangkatan
      $checkStmt = $this->conn->prepare("SELECT id FROM keberangkatan WHERE user_id = ?");
      $checkStmt->execute([$userId]);
      $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

      if ($exists) {
        // update existing departure document record
        $updateSql = "
                    UPDATE keberangkatan SET 
                        status_jamaah=?, plot=?, kloter=?, rombongan=?, regu=?, 
                        posisi_porsi=?, status_porsi=?, passport=?, status_passport=?, visa=?, status_visa=?, 
                        mutasi=?, bio_metrik=?, puskesmas=?, mcu=?, pelunasan=?, gform=?, foto=?, spph=?
                    WHERE user_id=?
                ";
        $stmt = $this->conn->prepare($updateSql);
        $stmt->execute([
          $statusJamaah,
          $plot,
          $kloter,
          $rombongan,
          $regu,
          $posisiPorsi,
          $statusPorsi,
          $passport,
          $statusPassport,
          $visa,
          $statusVisa,
          $mutasi,
          $bioMetrik,
          $puskesmas,
          $mcu,
          $pelunasan,
          $gform,
          $foto,
          $spph,
          $userId
        ]);
      } else {
        // insert new departure document record
        $insertSql = "
                    INSERT INTO keberangkatan (
                        user_id, status_jamaah, plot, kloter, rombongan, regu, 
                        posisi_porsi, status_porsi, passport, status_passport, visa, status_visa, 
                        mutasi, bio_metrik, puskesmas, mcu, pelunasan, gform, foto, spph
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
        $stmt = $this->conn->prepare($insertSql);
        $stmt->execute([
          $userId,
          $statusJamaah,
          $plot,
          $kloter,
          $rombongan,
          $regu,
          $posisiPorsi,
          $statusPorsi,
          $passport,
          $statusPassport,
          $visa,
          $statusVisa,
          $mutasi,
          $bioMetrik,
          $puskesmas,
          $mcu,
          $pelunasan,
          $gform,
          $foto,
          $spph
        ]);
      }

      // 5. OPTIONALLY UPDATE IS_COMPLETED STATUS IN USERS TABLE (WITH ZONE FILTER GUARD)
      if ($isCompleted !== null) {
        $userSql = "UPDATE users SET is_completed = :is_completed WHERE id = :user_id AND zona = :zone";
        $userStmt = $this->conn->prepare($userSql);
        $userStmt->execute([
          ':is_completed' => $isCompleted,
          ':user_id' => $userId,
          ':zone' => $zone
        ]);
      }

      // 6. COMMIT TRANSACTION
      $this->conn->commit();

      // 7. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Document data updated successfully!");

    } catch (Exception $e) {
      // rollback transaction on failure
      if ($this->conn->inTransaction()) {
        $this->conn->rollBack();
      }

      return $this->response(500, "error", "Failed to update document data: " . $e->getMessage());
    }
  }

  // delete user
  /**
   * Delete user account and associated data strictly restricted to Leader's zone
   * 
   * @param array $requestData
   * @return void
   */
  public function deleteUser($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';
    $leaderId = $requestData['leader_id'] ?? '';
    $zone = $requestData['zone'] ?? '';

    if (empty($userId)) {
      return $this->response(400, "error", "User ID is required.");
    }

    try {
      // 2. FETCH LEADER ZONE IF NOT PROVIDED FROM FRONTEND
      if (empty($zone)) {
        if (empty($leaderId)) {
          return $this->response(400, "error", "Leader ID or Zone is required for authorization.");
        }

        $queryZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryZone);
        $stmtZone->execute([':leader_id' => $leaderId]);
        $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "error", "Leader data or assigned zone not found.");
        }

        $zone = $leader['zona'];
      }

      // 3. VERIFY TARGET USER BELONGS TO LEADER'S ZONE BEFORE DELETION
      $verifyStmt = $this->conn->prepare("SELECT id FROM users WHERE id = :user_id AND zona = :zone LIMIT 1");
      $verifyStmt->execute([':user_id' => $userId, ':zone' => $zone]);

      if (!$verifyStmt->fetch()) {
        return $this->response(403, "error", "Unauthorized: Target user does not belong to your assigned zone.");
      }

      // 4. BEGIN TRANSACTION TO ENSURE DATA INTEGRITY
      $this->conn->beginTransaction();

      // delete related records from attendances table
      $stmtAttendances = $this->conn->prepare("DELETE FROM attendances WHERE user_id = ?");
      $stmtAttendances->execute([$userId]);

      // delete related records from keberangkatan table
      $stmtKeberangkatan = $this->conn->prepare("DELETE FROM keberangkatan WHERE user_id = ?");
      $stmtKeberangkatan->execute([$userId]);

      // delete user record from users table with zone verification guard
      $stmtUser = $this->conn->prepare("DELETE FROM users WHERE id = :user_id AND zona = :zone");
      $stmtUser->execute([
        ':user_id' => $userId,
        ':zone' => $zone
      ]);

      // 5. COMMIT TRANSACTION
      $this->conn->commit();

      // 6. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Jamaah account deleted successfully.");

    } catch (Exception $e) {
      // rollback transaction on failure
      if ($this->conn->inTransaction()) {
        $this->conn->rollBack();
      }

      return $this->response(500, "error", "Failed to delete user account: " . $e->getMessage());
    }
  }


}
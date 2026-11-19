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
  private function response($statusCode, $statusText, $message, $requestData = null)
  {
    http_response_code($statusCode);
    $payload = [
      "status" => $statusText,
      "message" => $message
    ];

    if ($requestData !== null) {
      $payload['data'] = $requestData;
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


  // create user basic account
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

  // udpate user profile: create an account
  /**
   * Update user profile data strictly restricted to Leader's zone
   * 
   * @param array $requestData
   * @return void
   */
  /**
   * Update user profile data strictly restricted to Leader's zone
   * 
   * @param array $requestData
   * @return void
   */
  public function updateUserProfile($requestData)
  {
    // 1. SANITIZE & VALIDATE REQUIRED IDENTIFIERS
    $userId = $requestData['user_id'] ?? '';
    $leaderId = $requestData['leader_id'] ?? '';

    if (empty($userId)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    if (empty($leaderId)) {
      return $this->response(400, "failed", "Leader ID is required for authorization.");
    }

    try {
      // 2. FETCH AUTHORIZED LEADER ZONE DIRECTLY FROM DATABASE
      $queryZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
      $stmtZone = $this->conn->prepare($queryZone);
      $stmtZone->execute([':leader_id' => $leaderId]);
      $leader = $stmtZone->fetch(PDO::FETCH_ASSOC);

      if (!$leader || empty($leader['zona'])) {
        return $this->response(404, "failed", "Leader data or assigned zone not found.");
      }

      $leaderZone = $leader['zona'];

      // 3. VERIFY TARGET USER BELONGS TO LEADER'S ZONE & FETCH EXISTING DATA
      $verifyUserStmt = $this->conn->prepare("SELECT * FROM users WHERE id = :user_id AND zona = :zone LIMIT 1");
      $verifyUserStmt->execute([':user_id' => $userId, ':zone' => $leaderZone]);
      $existingUser = $verifyUserStmt->fetch(PDO::FETCH_ASSOC);

      if (!$existingUser) {
        return $this->response(403, "failed", "Unauthorized: Target user does not belong to your assigned zone.");
      }

      // 4. EXTRACT FIELDS WITH FALLBACK TO EXISTING DATA TO PREVENT OVERWRITING
      $address = array_key_exists('address', $requestData) ? $requestData['address'] : $existingUser['address'];
      $birthDate = array_key_exists('birthDate', $requestData) ? $requestData['birthDate'] : $existingUser['birthDate'];
      $birthPlace = array_key_exists('birthPlace', $requestData) ? $requestData['birthPlace'] : $existingUser['birthPlace'];
      $companion = array_key_exists('companion', $requestData) ? $requestData['companion'] : $existingUser['companion'];
      $mahramName = array_key_exists('mahramName', $requestData) ? $requestData['mahramName'] : $existingUser['nama_mahram'];
      $education = array_key_exists('education', $requestData) ? $requestData['education'] : $existingUser['education'];
      $experience = array_key_exists('experience', $requestData) ? $requestData['experience'] : $existingUser['experience'];
      $fatherName = array_key_exists('fatherName', $requestData) ? $requestData['fatherName'] : $existingUser['fatherName'];
      $gender = array_key_exists('gender', $requestData) ? $requestData['gender'] : $existingUser['gender'];
      $isCompleted = array_key_exists('isCompleted', $requestData) ? (!empty($requestData['isCompleted']) ? 1 : 0) : $existingUser['is_completed'];
      $job = array_key_exists('job', $requestData) ? $requestData['job'] : $existingUser['job'];
      $program = array_key_exists('departure', $requestData) ? $requestData['departure'] : $existingUser['program'];
      $subDistrict = array_key_exists('subDistrict', $requestData) ? $requestData['subDistrict'] : $existingUser['subDistrict'];
      $village = array_key_exists('village', $requestData) ? $requestData['village'] : $existingUser['village'];

      // extract reference fields with dual-key support and fallback
      $referenceName = $requestData['referenceName'] ?? $requestData['referensi_nama'] ?? $existingUser['referensi_nama'];
      $referencePhone = $requestData['referencePhone'] ?? $requestData['referensi_wa'] ?? $existingUser['referensi_wa'];
      $referenceOrigin = $requestData['referenceOrigin'] ?? $requestData['referensi_asal'] ?? $existingUser['referensi_asal'];

      // format array payloads into comma-separated strings (unchanged as requested)
      $healthCondition = is_array($requestData['health'] ?? null) ? implode(", ", $requestData['health']) : $existingUser['healthCondition'];
      $positiveTrait = is_array($requestData['contribution'] ?? null) ? implode(", ", $requestData['contribution']) : $existingUser['positiveTrait'];
      $skill = is_array($requestData['expertise'] ?? null) ? implode(", ", $requestData['expertise']) : $existingUser['skill'];

      // extract profile image URL if present
      $photoUrl = $requestData['profileImage'] ?? $requestData['gambar'] ?? null;

      // 5. BUILD DYNAMIC SQL QUERY & EXECUTE WITH LEADER ZONE GUARD
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
        $leaderZone,
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
      $params[] = $leaderZone;

      $stmt = $this->conn->prepare($sql);
      $stmt->execute($params);

      // 6. RETURN STANDARDIZED RESPONSE
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

  // CRUD EVENT
  // create an event
  /**
   * Create a new event with material file upload support
   *
   * @param array $requestData Request payload
   * @param array $files Uploaded files ($_FILES)
   * @return void
   */
  // CREATE EVENT ENDPOINT LOGIC
  public function createEvent($requestData, $files)
  {
    // ==========================================
    // 1. SANITIZE & VALIDATE INPUTS
    // ==========================================
    $eventName = trim($requestData['eventName'] ?? '');
    $description = trim($requestData['description'] ?? '');
    $location = trim($requestData['location'] ?? '');
    $speaker = trim($requestData['speaker'] ?? '');

    // validate event category enum ('leader', 'jamaah')
    $categoryInput = strtolower(trim($requestData['category'] ?? ''));
    $category = in_array($categoryInput, ['leader', 'jamaah'], true) ? $categoryInput : 'jamaah';

    // validate target zone if category is 'leader' (convert to uppercase to match db enum)
    $allowedZona = ['A', 'B', 'C', 'D', 'E', 'F', 'umum'];
    $zoneInput = strtoupper(trim($requestData['zone'] ?? ''));
    $zone = in_array($zoneInput, $allowedZona, true) ? $zoneInput : null;

    // ensure leader category must have a valid target zone
    if ($category === 'leader' && empty($zone)) {
      return $this->response(400, "failed", "Zona target wajib diisi untuk event kategori leader.");
    }

    // validate event status enum ('mendatang', 'live', 'selesai')
    $statusInput = trim($requestData['status'] ?? '');
    $status = in_array($statusInput, ['mendatang', 'live', 'selesai'], true) ? $statusInput : 'mendatang';

    // format start time & end time
    $rawWaktuStart = trim($requestData['timeStart'] ?? '');
    $rawWaktuEnd = trim($requestData['timeEnd'] ?? '');

    $waktuEvent = null;
    $waktuEnd = null;

    if (!empty($rawWaktuStart)) {
      $timeFormatted = strtotime($rawWaktuStart);
      if ($timeFormatted !== false) {
        $waktuEvent = date('Y-m-d H:i:s', $timeFormatted);
      }
    }

    if (empty($waktuEvent)) {
      return $this->response(400, "failed", "Format tanggal dan waktu mulai event tidak valid.");
    }

    if (!empty($rawWaktuEnd)) {
      $timeFormatted = strtotime($rawWaktuEnd);
      if ($timeFormatted !== false) {
        $waktuEnd = date('Y-m-d H:i:s', $timeFormatted);
      }
    }

    if (empty($waktuEnd)) {
      return $this->response(400, "failed", "Format tanggal dan waktu selesai event tidak valid.");
    }

    if (strtotime($waktuEnd) <= strtotime($waktuEvent)) {
      return $this->response(400, "failed", "Waktu selesai event harus lebih besar dari waktu mulai.");
    }

    $latitude = isset($requestData['latitude']) && $requestData['latitude'] !== '' ? (float) $requestData['latitude'] : null;
    $longitude = isset($requestData['longitude']) && $requestData['longitude'] !== '' ? (float) $requestData['longitude'] : null;
    $radius = isset($requestData['radius']) ? (int) $requestData['radius'] : 100;

    if (empty($eventName) || $latitude === null || $longitude === null) {
      return $this->response(400, "failed", "Nama event, latitude, dan longitude wajib diisi.");
    }

    // generate unique qr code hash
    $qrHash = 'EVT-' . bin2hex(random_bytes(5));

    // ==========================================
    // 2. FILE UPLOAD HANDLING
    // ==========================================
    $relativePath = null;
    $uploadedTargetPath = null;

    if (isset($files['material']) && $files['material']['error'] === UPLOAD_ERR_OK) {
      $file = $files['material'];

      $allowedExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'];
      $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

      if (!in_array($fileExtension, $allowedExtensions, true)) {
        return $this->response(400, "failed", "Format file tidak didukung. Harap unggah PDF, Word, Excel, atau PPT.");
      }

      // maximum file size limit (10MB)
      if ($file['size'] > 10 * 1024 * 1024) {
        return $this->response(400, "failed", "Ukuran file materi melebihi batas maksimum (10MB).");
      }

      // ensure upload directory exists
      $uploadDir = __DIR__ . '/../uploads/';
      if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
      }

      // sanitize file name
      $rawFileNameOnly = pathinfo($file['name'], PATHINFO_FILENAME);
      $cleanFileName = strtolower($rawFileNameOnly);
      $cleanFileName = preg_replace('/\s+/', '_', $cleanFileName);
      $cleanFileName = preg_replace('/[^a-z0-9_\-]/', '', $cleanFileName);

      if (empty($cleanFileName)) {
        $cleanFileName = 'materi';
      }

      // generate unique file name with timestamp and random hash
      $uniqueSuffix = time() . '_' . bin2hex(random_bytes(3));
      $newFileName = "{$cleanFileName}_{$uniqueSuffix}.{$fileExtension}";
      $uploadedTargetPath = "{$uploadDir}{$newFileName}";
      $relativePath = "uploads/{$newFileName}";

      if (!move_uploaded_file($file['tmp_name'], $uploadedTargetPath)) {
        return $this->response(500, "failed", "Gagal mengunggah file ke server.");
      }
    }

    // ==========================================
    // 3. DATABASE TRANSACTION
    // ==========================================
    $this->conn->beginTransaction();

    try {
      $queryEvent = "INSERT INTO events 
            (nama_event, deskripsi_event, tempat, pembicara, latitude, longitude, radius, qr_hash, waktu_event, waktu_selesai, status, jenis_event, zona_target, file_path) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

      $stmt = $this->conn->prepare($queryEvent);
      $stmt->execute([
        $eventName,
        $description,
        $location,
        $speaker,
        $latitude,
        $longitude,
        $radius,
        $qrHash,
        $waktuEvent,
        $waktuEnd,
        $status,
        $category,
        $zone,
        $relativePath
      ]);

      $eventId = $this->conn->lastInsertId();

      // commit transaction
      $this->conn->commit();

      $responseData = [
        "event_id" => $eventId,
        "qr_hash" => $qrHash,
        "file_path" => $relativePath
      ];

      return $this->response(200, "success", "Event berhasil dibuat!", $responseData);

    } catch (Exception $e) {
      // rollback database transaction
      if ($this->conn->inTransaction()) {
        $this->conn->rollBack();
      }

      // clean up uploaded file if query failed
      if ($uploadedTargetPath && file_exists($uploadedTargetPath)) {
        unlink($uploadedTargetPath);
      }

      return $this->response(500, "failed", $e->getMessage());
    }
  }

  // get events
  /**
   * Fetch all registered events sorted by date
   *
   * @return void
   */
  /**
   * Fetch registered events filtered strictly by Leader's zone
   *
   * @param array $requestData
   * @return void
   */
  public function getEvents($requestData)
  {
    try {
      if (!$this->conn instanceof \PDO) {
        throw new \Exception("Koneksi database bukan instance PDO yang valid.");
      }

      // 1. SANITIZE & VALIDATE LEADER CREDENTIALS
      $leaderId = $requestData['leader_id'] ?? '';
      $zone = $requestData['zone'] ?? '';

      // 2. FETCH LEADER ZONE IF NOT PROVIDED FROM FRONTEND
      if (empty($zone)) {
        if (empty($leaderId)) {
          return $this->response(400, "failed", "Leader ID atau Zone diperlukan untuk otorisasi.");
        }

        $queryZone = "SELECT zona FROM users WHERE id = :leader_id AND role = 'leader' LIMIT 1";
        $stmtZone = $this->conn->prepare($queryZone);
        $stmtZone->execute([':leader_id' => $leaderId]);
        $leader = $stmtZone->fetch(\PDO::FETCH_ASSOC);

        if (!$leader || empty($leader['zona'])) {
          return $this->response(404, "failed", "Data leader atau zona wilayah tidak ditemukan.");
        }

        $zone = $leader['zona'];
      }

      $this->syncEventStatuses();

      // 3. FETCH EVENTS FILTERED BY LEADER'S ZONE WITH COLUMN ALIASES FOR FRONTEND
      $query = "SELECT 
                    id,
                    nama_event AS eventName,
                    tempat AS location,
                    pembicara AS speaker,
                    waktu_event AS date,
                    status,
                    jenis_event AS category,
                    zona_target AS zone
                  FROM events 
                  WHERE zona_target = :zone
                  ORDER BY waktu_event DESC";

      $stmt = $this->conn->prepare($query);
      $stmt->execute([':zone' => $zone]);
      $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

      // 4. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Daftar event berhasil diambil", $events);

    } catch (\Throwable $e) {
      return $this->response(500, "error", "Terjadi kesalahan pada server: " . $e->getMessage());
    }
  }


  // get event detail
  /**
   * Fetch detailed information for a single event by ID
   *
   * @param array $requestData Request payload containing event id
   * @return void
   */
  public function getEventDetail($requestData)
  {
    // 1. EXTRACT AND VALIDATE INPUTS
    $eventId = isset($requestData['id']) ? (int) $requestData['id'] : 0;
    $leaderId = isset($requestData['leader_id']) ? trim($requestData['leader_id']) : null;
    $zoneInput = isset($requestData['zone']) ? strtoupper(trim($requestData['zone'])) : null;

    if ($eventId === 0) {
      return $this->response(400, "failed", "ID Event wajib diisi dan harus berupa angka.");
    }

    if (empty($leaderId) || empty($zoneInput)) {
      return $this->response(401, "failed", "Akses ditolak. Identitas leader atau zona tidak valid.");
    }

    try {
      // 2. FETCH EVENT DATA WITH ZONE SECURITY CHECK
      // Memastikan leader hanya bisa melihat event yang sesuai dengan zonanya (atau zona 'umum')
      $queryEvent = "SELECT * FROM events WHERE id = ? AND (zona_target = ? OR zona_target = 'umum') LIMIT 1";

      $stmt = $this->conn->prepare($queryEvent);
      $stmt->execute([$eventId, $zoneInput]);
      $eventData = $stmt->fetch(\PDO::FETCH_ASSOC);

      if (!$eventData) {
        return $this->response(404, "failed", "Data event tidak ditemukan atau Anda tidak memiliki hak akses ke zona ini.");
      }

      // 3. MAP RESPONSE PAYLOAD TO CAMELCASE
      $responsePayload = [
        "id" => (int) $eventData['id'],
        "eventName" => $eventData['nama_event'],
        "description" => $eventData['deskripsi_event'],
        "location" => $eventData['tempat'],
        "speaker" => $eventData['pembicara'],
        "latitude" => $eventData['latitude'] !== null ? (float) $eventData['latitude'] : null,
        "longitude" => $eventData['longitude'] !== null ? (float) $eventData['longitude'] : null,
        "radius" => (int) $eventData['radius'],
        "qrHash" => $eventData['qr_hash'],
        "timeStart" => $eventData['waktu_event'],
        "timeEnd" => $eventData['waktu_selesai'],
        "status" => $eventData['status'],
        "category" => $eventData['jenis_event'],
        "zone" => $eventData['zona_target'],
        "material" => $eventData['file_path']
      ];

      // 4. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Event detail retrieved successfully", $responsePayload);

    } catch (\Throwable $e) {
      return $this->response(500, "failed", "Server error: " . $e->getMessage());
    }
  }


  // udpate event
  /**
   * Update existing event details and material file
   *
   * @param array $requestData Request payload
   * @param array $files Uploaded files ($_FILES)
   * @return void
   */
  // UPDATE EVENT ENDPOINT LOGIC
  public function updateEvent($requestData, $files = [])
  {
    try {
      // 1. VALIDATE EVENT ID
      $eventId = isset($requestData['event_id']) ? (int) $requestData['event_id'] : 0;
      $leaderId = isset($requestData['leader_id']) ? trim($requestData['leader_id']) : null;
      $zoneInput = isset($requestData['zone']) ? strtoupper(trim($requestData['zone'])) : null;

      if ($eventId === 0) {
        return $this->response(400, "failed", "Id event tidak ditemukan.");
      }

      if (empty($leaderId) || empty($zoneInput)) {
        return $this->response(401, "failed", "Akses ditolak. Identitas leader atau zona tidak valid.");
      }

      // 2. FETCH EXISTING EVENT DATA TO CHECK EXISTENCE & OLD FILE
      $stmtOld = $this->conn->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
      $stmtOld->execute([$eventId]);
      $currentEvent = $stmtOld->fetch(\PDO::FETCH_ASSOC);

      if (!$currentEvent) {
        return $this->response(404, "failed", "Data event tidak ditemukan.");
      }

      if (empty($leaderId) || empty($zoneInput)) {
        return $this->response(401, "failed", "Akses ditolak. Identitas leader atau zona tidak valid.");
      }

      if ($currentEvent['zona_target'] !== $zoneInput) {
        return $this->response(403, "failed", "Akses ditolak. Anda hanya dapat mengubah event yang ada di zona Anda sendiri.");
      }

      // 3. MAP & SANITIZE PAYLOAD DATA
      $namaEvent = trim($requestData['eventName'] ?? '');
      $deskripsiEvent = trim($requestData['description'] ?? '');
      $tempat = trim($requestData['location'] ?? '');
      $pembicara = trim($requestData['speaker'] ?? '');

      // validate event category enum ('leader', 'jamaah')
      $categoryInput = strtolower(trim($requestData['category'] ?? ''));
      $jenisEvent = in_array($categoryInput, ['leader', 'jamaah'], true) ? $categoryInput : 'jamaah';

      // validate target zone if category is 'leader'
      $allowedZona = ['A', 'B', 'C', 'D', 'E', 'F', 'umum'];
      $targetZoneInput = strtoupper(trim($requestData['zone'] ?? ''));
      $zonaTarget = in_array($targetZoneInput, $allowedZona, true) ? $targetZoneInput : null;

      if ($jenisEvent === 'leader' && empty($zonaTarget)) {
        return $this->response(400, "failed", "Zona target wajib diisi untuk event kategori leader.");
      }

      // validate event status enum ('mendatang', 'live', 'selesai')
      $statusInput = trim($requestData['status'] ?? '');
      $status = in_array($statusInput, ['mendatang', 'live', 'selesai'], true) ? $statusInput : 'mendatang';

      // format start time & end time
      $rawWaktuStart = trim($requestData['timeStart'] ?? '');
      $rawWaktuEnd = trim($requestData['timeEnd'] ?? '');

      $waktuEvent = null;
      $waktuSelesai = null;

      if (!empty($rawWaktuStart)) {
        $timeStartFormatted = strtotime($rawWaktuStart);
        if ($timeStartFormatted !== false) {
          $waktuEvent = date('Y-m-d H:i:s', $timeStartFormatted);
        }
      }

      if (empty($waktuEvent)) {
        return $this->response(400, "failed", "Format tanggal dan waktu mulai event tidak valid.");
      }

      if (!empty($rawWaktuEnd)) {
        $timeEndFormatted = strtotime($rawWaktuEnd);
        if ($timeEndFormatted !== false) {
          $waktuSelesai = date('Y-m-d H:i:s', $timeEndFormatted);
        }
      }

      if (empty($waktuSelesai)) {
        return $this->response(400, "failed", "Format tanggal dan waktu selesai event tidak valid.");
      }

      if (strtotime($waktuSelesai) <= strtotime($waktuEvent)) {
        return $this->response(400, "failed", "Waktu selesai event harus lebih besar dari waktu mulai.");
      }

      $latitude = isset($requestData['latitude']) && $requestData['latitude'] !== '' ? (float) $requestData['latitude'] : null;
      $longitude = isset($requestData['longitude']) && $requestData['longitude'] !== '' ? (float) $requestData['longitude'] : null;
      $radius = isset($requestData['radius']) ? (int) $requestData['radius'] : 100;

      if (empty($namaEvent) || $latitude === null || $longitude === null) {
        return $this->response(400, "failed", "Nama event, latitude, dan longitude wajib diisi.");
      }

      $filePath = $currentEvent['file_path'];

      // 4. HANDLE UPLOAD NEW MATERIAL FILE
      if (isset($files['material']) && $files['material']['error'] === UPLOAD_ERR_OK) {
        $file = $files['material'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // validate file extension
        $allowedExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'];
        if (!in_array($fileExtension, $allowedExtensions, true)) {
          return $this->response(400, "failed", "Format file tidak didukung.");
        }

        // maximum file size limit (10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
          return $this->response(400, "failed", "Ukuran file materi melebihi batas maksimum (10MB).");
        }

        // sanitize raw file name
        $rawFileNameOnly = pathinfo($file['name'], PATHINFO_FILENAME);
        $cleanFileName = strtolower($rawFileNameOnly);
        $cleanFileName = preg_replace('/\s+/', '_', $cleanFileName);
        $cleanFileName = preg_replace('/[^a-z0-9_\-]/', '', $cleanFileName);

        if (empty($cleanFileName)) {
          $cleanFileName = 'materi';
        }

        // generate unique file name
        $uniqueSuffix = time() . '_' . bin2hex(random_bytes(3));
        $newFileName = "{$cleanFileName}_{$uniqueSuffix}.{$fileExtension}";

        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
          mkdir($uploadDir, 0755, true);
        }

        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
          // delete old file from server if exists
          if (!empty($currentEvent['file_path'])) {
            $oldFileFullPath = __DIR__ . '/../' . $currentEvent['file_path'];
            if (file_exists($oldFileFullPath)) {
              @unlink($oldFileFullPath);
            }
          }

          // update relative path
          $filePath = 'uploads/' . $newFileName;
        } else {
          return $this->response(500, "failed", "Gagal mengunggah file baru ke server.");
        }
      }

      // 5. UPDATE EVENT IN DATABASE
      $sql = "UPDATE events SET 
                    nama_event = ?, 
                    deskripsi_event = ?, 
                    tempat = ?, 
                    pembicara = ?, 
                    jenis_event = ?, 
                    zona_target = ?, 
                    waktu_event = ?,
                    waktu_selesai = ?,
                    status = ?,
                    latitude = ?, 
                    longitude = ?, 
                    radius = ?, 
                    file_path = ? 
                WHERE id = ?";

      $stmt = $this->conn->prepare($sql);

      $params = [
        $namaEvent,
        $deskripsiEvent,
        $tempat,
        $pembicara,
        $jenisEvent,
        $zonaTarget,
        $waktuEvent,
        $waktuSelesai,
        $status,
        $latitude,
        $longitude,
        $radius,
        $filePath,
        $eventId
      ];

      if ($stmt->execute($params)) {
        $responseData = [
          "event_id" => $eventId,
          "file_path" => $filePath
        ];
        return $this->response(200, "success", "Event berhasil diperbarui.", $responseData);
      } else {
        $errorInfo = $stmt->errorInfo();
        return $this->response(500, "error", "Gagal memperbarui database: " . ($errorInfo[2] ?? 'Unknown error'));
      }

    } catch (\PDOException $e) {
      return $this->response(500, "error", "Terjadi kesalahan database: " . $e->getMessage());
    } catch (\Exception $e) {
      return $this->response(500, "error", "Terjadi kesalahan sistem: " . $e->getMessage());
    }
  }


  // delete event
  /**
   * Delete event and remove associated material file
   *
   * @param array $requestData Request payload containing event ID
   * @return void
   */
  public function deleteEvent($requestData)
  {
    // 1. EXTRACT AND VALIDATE EVENT ID & LEADER IDENTITY
    $eventId = $requestData['id'] ?? $requestData['eventId'] ?? $requestData['event_id'] ?? null;
    $leaderId = isset($requestData['leader_id']) ? trim($requestData['leader_id']) : null;
    $zoneInput = isset($requestData['zone']) ? strtoupper(trim($requestData['zone'])) : null;

    if (!$eventId || !is_numeric($eventId)) {
      return $this->response(400, "error", "ID event tidak valid atau tidak ditemukan.");
    }

    if (empty($leaderId) || empty($zoneInput)) {
      return $this->response(401, "error", "Akses ditolak. Identitas leader atau zona tidak valid.");
    }

    try {
      if (!$this->conn instanceof \PDO) {
        throw new \Exception("Koneksi database bukan instance PDO yang valid.");
      }

      $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

      // 2. FETCH EVENT DATA TO CHECK EXISTENCE & ZONE AUTHORIZATION
      $stmtGetEvent = $this->conn->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
      $stmtGetEvent->execute([$eventId]);
      $eventData = $stmtGetEvent->fetch(\PDO::FETCH_ASSOC);

      if (!$eventData) {
        return $this->response(404, "error", "Event dengan ID tersebut tidak ditemukan.");
      }

      // ==========================================
      // VALIDASI OTORISASI KETAT: HANYA ZONA SENDIRI
      // ==========================================
      if ($eventData['zona_target'] !== $zoneInput) {
        return $this->response(403, "error", "Akses ditolak. Anda hanya dapat menghapus event yang ada di zona Anda sendiri.");
      }
      // ==========================================

      $filePath = $eventData['file_path'];

      // 3. DELETE EVENT RECORD FROM DATABASE
      $stmtEvent = $this->conn->prepare("DELETE FROM events WHERE id = ?");
      $stmtEvent->execute([$eventId]);

      if ($stmtEvent->rowCount() > 0) {
        // 4. REMOVE ASSOCIATED FILE FROM SERVER IF EXISTS
        if (!empty($filePath)) {
          $fullPath = __DIR__ . '/../' . $filePath;
          if (file_exists($fullPath)) {
            @unlink($fullPath);
          }
        }

        return $this->response(200, "success", "Event dan file materi terkait berhasil dihapus.");
      } else {
        return $this->response(404, "error", "Gagal menghapus event dari database.");
      }

    } catch (\Throwable $e) {
      return $this->response(500, "error", "Terjadi kesalahan pada server: " . $e->getMessage());
    }
  }

}
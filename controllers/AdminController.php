<?php

class AdminController
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

  // update kuota province
  /**
   * Update province quota configuration in database
   *
   * @param array $data Request payload containing provinceQuota
   * @return void
   */
  public function updateKuota($data)
  {
    // 1. extract payload
    $kuota = $data['provinceQuota'] ?? '';

    // 2. validate quota input (ensure it is present and numeric)
    if ($kuota === '' || !is_numeric($kuota)) {
      return $this->response(400, "failed", "Nilai kuota tidak valid atau kosong.");
    }

    try {
      $key = 'kuota_jabar'; // hardcoded setting key for quota

      // 3. execute upsert query (insert or update on duplicate key)
      $stmt = $this->conn->prepare("
        INSERT INTO app_settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = ?
      ");

      $stmt->execute([$key, $kuota, $kuota]);

      // 4. return success response
      return $this->response(200, "success", "Kuota provinsi berhasil diperbarui.");

    } catch (Exception $e) {
      // 5. handle database exception
      return $this->response(500, "error", "Terjadi kesalahan pada server: " . $e->getMessage());
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
  public function createUser($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $nama = trim($requestData['nama_lengkap'] ?? '');
    $porsi = trim($requestData['nomor_porsi'] ?? '');
    $wa = trim($requestData['whatsapp'] ?? '');
    $password = $requestData['password'] ?? '';
    $role = $requestData['role'] ?? '';

    if (empty($nama) || empty($porsi) || empty($wa) || empty($password)) {
      return $this->response(400, "error", "All fields (nama_lengkap, nomor_porsi, whatsapp, password) are required.");
    }

    try {
      // 2. CHECK FOR EXISTING PORTION NUMBER OR WHATSAPP
      $checkStmt = $this->conn->prepare("SELECT id FROM users WHERE nomor_porsi = ? OR whatsapp = ?");
      $checkStmt->execute([$porsi, $wa]);

      if ($checkStmt->rowCount() > 0) {
        return $this->response(409, "error", "Portion number or WhatsApp is already registered!");
      }

      // 3. HASH PASSWORD AND PREPARE USER DATA
      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);


      // 4. INSERT NEW USER RECORD
      $insertSql = "
                INSERT INTO users (nama_lengkap, nomor_porsi, whatsapp, password, role, is_completed) 
                VALUES (?, ?, ?, ?, ?, 0)
            ";
      $stmt = $this->conn->prepare($insertSql);
      $stmt->execute([$nama, $porsi, $wa, $hashedPassword, $role]);

      $newUserId = (int) $this->conn->lastInsertId();

      // 5. RETURN STANDARDIZED RESPONSE
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
   * Fetch simplified user list formatted for frontend admin tables
   *
   * @return void
   */
  public function getUsers()
  {
    try {
      // 1. FETCH USERS WITH FORMATTED ALIASES
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
      ";

      $stmt_users = $this->conn->prepare($querySql);
      $stmt_users->execute();
      $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

      // 2. RETURN RESPONSE
      return $this->response(200, "success", "Successfully retrieved all users.", $users);

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

    if (empty($user_id)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    try {
      // 2. FETCH USER AND DEPARTURE DETAILS
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
                WHERE u.id = ?
            ";

      $stmt = $this->conn->prepare($sql);
      $stmt->execute([$user_id]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      // 3. CHECK USER EXISTENCE AND MAP DATA
      if (!$user) {
        return $this->response(404, "failed", "User ID not found.");
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

      // 4. RETURN STANDARDIZED RESPONSE
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
   * Update user profile information
   * 
   * @param array $requestData
   * @return void
   */
  public function updateUserProfile($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';

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

    // calculate zone dynamically
    $zone = $this->determineZone($subDistrict);

    // format array payloads into comma-separated strings
    $healthCondition = is_array($requestData['health'] ?? null) ? implode(", ", $requestData['health']) : '';
    $positiveTrait = is_array($requestData['contribution'] ?? null) ? implode(", ", $requestData['contribution']) : '';
    $skill = is_array($requestData['expertise'] ?? null) ? implode(", ", $requestData['expertise']) : '';

    // extract profile image URL if present
    $photoUrl = $requestData['profileImage'] ?? $requestData['gambar'] ?? null;

    try {
      // 2. BUILD DYNAMIC SQL QUERY & EXECUTE
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
                    WHERE id=?
                ";
        $params[] = $photoUrl;
      } else {
        $sql = "
                    UPDATE users SET 
                        address=?, birthDate=?, birthPlace=?, companion=?, nama_mahram=?, education=?, 
                        experience=?, fatherName=?, gender=?, healthCondition=?, is_completed=?, 
                        job=?, positiveTrait=?, program=?, skill=?, subDistrict=?, zona=?, village=?, 
                        referensi_nama=?, referensi_wa=?, referensi_asal=? 
                    WHERE id=?
                ";
      }

      $params[] = $userId;

      $stmt = $this->conn->prepare($sql);
      $stmt->execute($params);

      // 3. RETURN STANDARDIZED RESPONSE
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
   * Edit user profile and departure documents dynamically across two tables
   * 
   * @param array $requestData
   * @return void
   */
  public function editUsers($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';

    if (empty($userId)) {
      return $this->response(400, "failed", "User ID is required.");
    }

    try {
      // 2. BEGIN TRANSACTION TO ENSURE DATA CONSISTENCY
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
        // map frontend zone key to database zona column if present
        if (array_key_exists('zone', $requestData)) {
          $requestData['zona'] = $requestData['zone'];
        }

        // calculate zone dynamically if subDistrict exists but zona is absent
        if (array_key_exists('subDistrict', $requestData) && !array_key_exists('zona', $requestData)) {
          $requestData['zona'] = $this->determineZone($requestData['subDistrict']);
        }

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
          'zone' => 'zona',
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
          $updateUserSql = "UPDATE users SET " . implode(", ", $sets) . " WHERE id = ?";
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

      // 3. COMMIT TRANSACTION
      $this->conn->commit();

      // 4. RETURN STANDARDIZED RESPONSE
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
   * Update or create departure document details and sync user completion status
   * 
   * @param array $requestData
   * @return void
   */
  public function updateDocument($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';

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
      // 2. BEGIN TRANSACTION TO MAINTAIN DUAL-TABLE CONSISTENCY
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

      // 3. OPTIONALLY UPDATE IS_COMPLETED STATUS IN USERS TABLE
      if ($isCompleted !== null) {
        $userSql = "UPDATE users SET is_completed = ? WHERE id = ?";
        $userStmt = $this->conn->prepare($userSql);
        $userStmt->execute([$isCompleted, $userId]);
      }

      // 4. COMMIT TRANSACTION
      $this->conn->commit();

      // 5. RETURN STANDARDIZED RESPONSE
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
   * Delete user account and related records from dependent tables
   * 
   * @param array $requestData
   * @return void
   */
  public function deleteUser($requestData)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $userId = $requestData['user_id'] ?? '';

    if (empty($userId)) {
      return $this->response(400, "error", "User ID is required.");
    }

    try {
      // 2. BEGIN TRANSACTION TO ENSURE DATA INTEGRITY
      $this->conn->beginTransaction();

      // delete related records from attendances table
      $stmtAttendances = $this->conn->prepare("DELETE FROM attendances WHERE user_id = ?");
      $stmtAttendances->execute([$userId]);

      // delete related records from keberangkatan table
      $stmtKeberangkatan = $this->conn->prepare("DELETE FROM keberangkatan WHERE user_id = ?");
      $stmtKeberangkatan->execute([$userId]);

      // delete user record from users table
      $stmtUser = $this->conn->prepare("DELETE FROM users WHERE id = ?");
      $stmtUser->execute([$userId]);

      // 3. COMMIT TRANSACTION
      $this->conn->commit();

      // 4. RETURN STANDARDIZED RESPONSE
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
   * @param array $data Request payload
   * @param array $files Uploaded files ($_FILES)
   * @return void
   */
  public function createEvent($data, $files)
  {
    // 1. SANITIZE & VALIDATE INPUTS
    $eventName = trim($data['eventName'] ?? '');
    $description = trim($data['description'] ?? '');
    $location = trim($data['location'] ?? '');
    $speaker = trim($data['speaker'] ?? '');

    // validate event category enum ('leader', 'jamaah')
    $categoryInput = trim($data['category'] ?? '');
    $category = in_array($categoryInput, ['leader', 'jamaah'], true) ? $categoryInput : 'jamaah';

    // validate target zone if category is 'leader'
    $allowedZona = ['A', 'B', 'C', 'D', 'E', 'F'];
    $zoneInput = trim($data['zone'] ?? '');
    $zone = ($category === 'leader' && in_array($zoneInput, $allowedZona, true)) ? $zoneInput : null;

    // validate event status enum ('mendatang', 'live', 'selesai')
    $statusInput = trim($data['status'] ?? '');
    $status = in_array($statusInput, ['mendatang', 'live', 'selesai', 'upcoming'], true) ? $statusInput : 'mendatang';

    // format start time & end time
    $rawWaktuStart = trim($data['timeStart'] ?? '');
    $rawWaktuEnd = trim($data['timeEnd'] ?? '');

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

    $latitude = isset($data['latitude']) && $data['latitude'] !== '' ? (float) $data['latitude'] : null;
    $longitude = isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : null;
    $radius = isset($data['radius']) ? (int) $data['radius'] : 100;

    if (empty($eventName) || $latitude === null || $longitude === null) {
      return $this->response(400, "failed", "Nama event, latitude, dan longitude wajib diisi.");
    }

    // generate unique qr code hash
    $qrHash = 'EVT-' . bin2hex(random_bytes(5));

    // 2. FILE UPLOAD HANDLING
    $relativePath = null;
    $uploadedTargetPath = null; // store path for rollback if database insert fails

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

    // 3. DATABASE TRANSACTION
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
  public function getEvents()
  {
    try {
      if (!$this->conn instanceof \PDO) {
        throw new \Exception("Koneksi database bukan instance PDO yang valid.");
      }

      $this->syncEventStatuses();

      // 1. FETCH EVENTS WITH COLUMN ALIASES FOR FRONTEND
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
                  ORDER BY waktu_event DESC";

      $stmt = $this->conn->prepare($query);
      $stmt->execute();
      $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

      // 2. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Daftar event berhasil diambil", $events);

    } catch (\Throwable $e) {
      return $this->response(500, "error", "Terjadi kesalahan pada server: " . $e->getMessage());
    }
  }


  // get event detail
  /**
   * Fetch detailed information for a single event by ID
   *
   * @param array $data Request payload containing event id
   * @return void
   */
  public function getEventDetail($data)
  {
    // 1. EXTRACT AND VALIDATE EVENT ID
    $eventId = isset($data['id']) ? (int) $data['id'] : 0;

    if ($eventId === 0) {
      return $this->response(400, "failed", "ID Event wajib diisi dan harus berupa angka.");
    }

    try {
      // 2. FETCH EVENT DATA FROM DATABASE
      $queryEvent = "SELECT * FROM events WHERE id = ? LIMIT 1";
      $stmt = $this->conn->prepare($queryEvent);
      $stmt->execute([$eventId]);
      $eventData = $stmt->fetch(\PDO::FETCH_ASSOC);

      if (!$eventData) {
        return $this->response(404, "failed", "Data event tidak ditemukan.");
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
   * @param array $data Request payload
   * @param array $files Uploaded files ($_FILES)
   * @return void
   */
  public function updateEvent($data, $files = [])
  {
    try {
      // 1. VALIDATE EVENT ID
      $eventId = $data['event_id'] ?? null;
      if (!$eventId) {
        return $this->response(400, "failed", "Parameter event_id wajib diisi.");
      }

      // 2. MAP & SANITIZE PAYLOAD DATA
      $namaEvent = $data['eventName'] ?? '';
      $deskripsiEvent = $data['description'] ?? '';
      $tempat = $data['location'] ?? '';
      $pembicara = $data['speaker'] ?? '';
      $jenisEvent = $data['category'] ?? 'jamaah';
      $zonaTarget = $data['zone'] ?? null;
      $waktuEvent = !empty($data['timeStart']) ? $data['timeStart'] : null;
      $waktuSelesai = !empty($data['timeEnd']) ? $data['timeEnd'] : null;
      $status = $data['status'] ?? null;
      $latitude = !empty($data['latitude']) ? $data['latitude'] : null;
      $longitude = !empty($data['longitude']) ? $data['longitude'] : null;
      $radius = isset($data['radius']) ? (int) $data['radius'] : 100;

      // 3. FETCH EXISTING EVENT DATA TO CHECK FOR OLD FILE
      $stmtOld = $this->conn->prepare("SELECT file_path FROM events WHERE id = ?");
      $stmtOld->execute([$eventId]);
      $currentEvent = $stmtOld->fetch(\PDO::FETCH_ASSOC);

      if (!$currentEvent) {
        return $this->response(404, "failed", "Data event tidak ditemukan.");
      }

      $filePath = $currentEvent['file_path'];

      // 4. HANDLE UPLOAD NEW MATERIAL FILE
      if (isset($files['material']) && $files['material']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $files['material']['tmp_name'];
        $fileName = $files['material']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // validate file extension
        $allowedExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'];
        if (!in_array($fileExtension, $allowedExtensions, true)) {
          return $this->response(400, "failed", "Format file tidak didukung.");
        }

        // sanitize raw file name
        $rawFileNameOnly = pathinfo($fileName, PATHINFO_FILENAME);
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

        if (move_uploaded_file($fileTmpPath, $destPath)) {
          // delete old file from server if exists
          if (!empty($currentEvent['file_path'])) {
            $oldFileFullPath = __DIR__ . '/../' . $currentEvent['file_path'];
            if (file_exists($oldFileFullPath)) {
              @unlink($oldFileFullPath);
            }
          }

          // update relative path
          $filePath = 'uploads/' . $newFileName;
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
   * @param array $data Request payload containing event ID
   * @return void
   */
  public function deleteEvent($data)
  {
    // 1. EXTRACT AND VALIDATE EVENT ID
    $eventId = $data['id'] ?? $data['eventId'] ?? $data['event_id'] ?? null;

    if (!$eventId || !is_numeric($eventId)) {
      return $this->response(400, "error", "ID event tidak valid atau tidak ditemukan.");
    }

    try {
      if (!$this->conn instanceof \PDO) {
        throw new \Exception("Koneksi database bukan instance PDO yang valid.");
      }

      $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

      // 2. FETCH FILE PATH BEFORE DELETING EVENT RECORD
      $stmtGetFile = $this->conn->prepare("SELECT file_path FROM events WHERE id = ?");
      $stmtGetFile->execute([$eventId]);
      $eventData = $stmtGetFile->fetch(\PDO::FETCH_ASSOC);

      if (!$eventData) {
        return $this->response(404, "error", "Event dengan ID tersebut tidak ditemukan.");
      }

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


  // LANDING PAGE
  // get landing page data: user
  /**
   * Fetch all landing page content sections for user client
   *
   * @return void
   */
  public function getLandingContents()
  {
    try {
      if (!$this->conn instanceof \PDO) {
        throw new \Exception("Koneksi database bukan instance PDO yang valid.");
      }

      // 1. FETCH LANDING SECTIONS CONTENT FROM DATABASE
      $query = "SELECT content FROM landing_sections";

      $stmt = $this->conn->prepare($query);
      $stmt->execute();
      $landingContent = $stmt->fetchAll(\PDO::FETCH_ASSOC);

      // 2. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Landing page berhasil diambil", $landingContent);

    } catch (\Throwable $e) {
      return $this->response(500, "error", "Terjadi kesalahan pada server: " . $e->getMessage());
    }
  }


  // get sectiopn data: admin
  /**
   * Get landing section content by section key for admin panel
   *
   * @param array $requestData Request payload
   * @return void
   */
  public function getSectionContent($requestData)
  {
    // 1. EXTRACT SECTION KEY FROM PAYLOAD OR QUERY PARAMETERS
    $sectionKey = $requestData['section_key'] ?? $_GET['section_key'] ?? 'hero';

    try {
      // 2. QUERY SPECIFIC SECTION FROM DATABASE
      $query = "SELECT section_key, is_active, sort_order, content FROM landing_sections WHERE section_key = ?";
      $stmt = $this->conn->prepare($query);
      $stmt->execute([$sectionKey]);
      $section = $stmt->fetch(\PDO::FETCH_ASSOC);

      if (!$section) {
        return $this->response(404, "failed", "Landing section not found");
      }

      // 3. DECODE STORED JSON STRING BACK TO ARRAY FOR FRONTEND
      $section['content'] = json_decode($section['content'], true);

      // 4. RETURN STANDARDIZED RESPONSE
      return $this->response(200, "success", "Landing section retrieved successfully", $section);

    } catch (\PDOException $e) {
      return $this->response(500, "failed", "Database error: " . $e->getMessage());
    }
  }


  // update section data
  /**
   * Update or insert landing section content by section key
   * @param array $requestData
   * @return void
   */
  /**
   * Update or insert landing section content
   *
   * @param array $requestData Request payload
   * @return void
   */
  public function updateSectionContent($requestData)
  {
    // 1. EXTRACT & VALIDATE PAYLOAD
    $sectionKey = $requestData['section_key'] ?? 'hero';
    $content = $requestData['content'] ?? null;

    if (!$content) {
      return $this->response(400, "failed", "Data tidak ada, silahkan isi dengan benar");
    }

    try {
      // 2. CHECK IF SECTION EXISTS IN DATABASE
      $checkQuery = "SELECT id FROM landing_sections WHERE section_key = ?";
      $stmtCheck = $this->conn->prepare($checkQuery);
      $stmtCheck->execute([$sectionKey]);
      $section = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

      // encode content array or object to json string for database storage
      $jsonContent = is_string($content) ? $content : json_encode($content);

      if ($section) {
        // 3. UPDATE EXISTING SECTION CONTENT
        $updateQuery = "UPDATE landing_sections SET content = ?, updated_at = NOW() WHERE section_key = ?";
        $stmtUpdate = $this->conn->prepare($updateQuery);
        $stmtUpdate->execute([$jsonContent, $sectionKey]);
      } else {
        // 4. INSERT NEW SECTION IF NOT EXIST
        $insertQuery = "INSERT INTO landing_sections (section_key, content, is_active, sort_order) VALUES (?, ?, 1, 1)";
        $stmtInsert = $this->conn->prepare($insertQuery);
        $stmtInsert->execute([$sectionKey, $jsonContent]);
      }

      // 5. RETURN STANDARDIZED RESPONSE
      $responseData = [
        "section_key" => $sectionKey
      ];

      return $this->response(200, "success", "Landing page berhasil di update", $responseData);

    } catch (\PDOException $e) {
      return $this->response(500, "failed", "Database error: " . $e->getMessage());
    }
  }
}
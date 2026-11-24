<?php

class UserController
{
  private $conn;

  public function __construct($dbConnection)
  {
    $this->conn = $dbConnection;
  }

  /**
   * Helper privat untuk mengembalikan response JSON standar
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

  // UPDATE PASSWORD
  public function updatePassword($data)
  {
    // 1. Ambil data input
    $user_id = $data['user_id'] ?? '';
    $password_baru = $data['newPassword'] ?? '';

    // 2. Validasi input kosong
    if (empty($user_id) || empty($password_baru)) {
      return $this->response(400, "failed", "User ID dan password baru wajib diisi.");
    }

    try {
      // 3. Cek keberadaan user di database
      $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ?");
      $stmt->execute([$user_id]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$user) {
        return $this->response(404, "failed", "User tidak ditemukan.");
      }

      // 4. Hash password baru
      $new_password_hashed = password_hash($password_baru, PASSWORD_DEFAULT);

      // 5. Eksekusi update password
      $update_stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
      $update_stmt->execute([$new_password_hashed, $user_id]);

      // 6. Berikan respon sukses
      return $this->response(200, "success", "Password berhasil diperbarui.");

    } catch (Exception $e) {
      return $this->response(500, "error", "Terjadi kesalahan pada server: " . $e->getMessage());
    }
  }

  // GET PROFILE
  /**
   * Get complete user profile with departure details
   *
   * @param array $data Request payload containing user_id
   * @return void
   */
  public function getProfile($data)
  {
    // 1. Retrieve user_id from payload (fallback to $_GET if missing)
    $user_id = $data['user_id'] ?? $_GET['user_id'] ?? '';

    // 2. Validate user_id
    if (empty($user_id)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    try {
      // 3. Fetch user data along with departure details via LEFT JOIN
      $stmt = $this->conn->prepare("
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
      ");
      $stmt->execute([$user_id]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      // 4. Return formatted user profile or 404 if not found
      if ($user) {
        $profileData = [
          // Basic Profile & Personal Details
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

          // Departure & Travel Information (Default fallbacks if null)
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

          // Document & Verification Status
          "mutationStatus" => $user['mutasi'] ?? 'menunggu',
          "biometricStatus" => $user['bio_metrik'] ?? 'menunggu',
          "puskesmasStatus" => $user['puskesmas'] ?? 'menunggu',
          "mcuStatus" => $user['mcu'] ?? 'menunggu',
          "paymentStatus" => $user['pelunasan'] ?? 'menunggu',
          "googleFormStatus" => $user['gform'] ?? 'menunggu',
          "photoStatus" => $user['foto_berkas'] ?? 'menunggu',
          "spphStatus" => $user['spph'] ?? 'menunggu',
        ];

        return $this->response(200, "success", "Profile data retrieved successfully.", $profileData);
      } else {
        return $this->response(404, "failed", "User ID not found.");
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Database Error: " . $e->getMessage());
    }
  }

  // helper update profile: fill the form identity
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


  // GET EVENT DASHBOARD
  /**
   * Fetch live or upcoming dashboard event targeted for a specific user
   *
   * @param array $data Request payload containing user_id
   * @return void
   */
  public function getDashboardEvent($data)
  {
    // 1. Retrieve user_id from request body or query parameter
    $user_id = $data['user_id'] ?? $_GET['user_id'] ?? '';

    // 2. Validate required user_id
    if (empty($user_id)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    try {
      // 3. Get targeted user's zone
      $stmt_user = $this->conn->prepare("SELECT zona FROM users WHERE id = ?");
      $stmt_user->execute([$user_id]);
      $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
      $zona_user = $user['zona'] ?? '';

      // 4. Query single relevant event (live/upcoming matching user's zone or general events)
      $sql = "
        SELECT 
          e.id,
          e.nama_event AS eventName,
          e.deskripsi_event AS description,
          e.tempat AS location,
          e.pembicara AS speaker,
          e.waktu_event AS timeStart,
          e.waktu_selesai AS timeEnd,
          e.status,
          e.jenis_event AS category,
          e.file_path,
          EXISTS(SELECT 1 FROM attendances a WHERE a.event_id = e.id AND a.user_id = ?) AS isAttended
        FROM events e
        WHERE (e.jenis_event = 'jamaah' OR e.zona_target = ?) 
          AND e.status IN ('live', 'mendatang')
        ORDER BY 
          FIELD(e.status, 'live', 'mendatang'), 
          e.waktu_event ASC, 
          e.id ASC
        LIMIT 1
      ";

      $stmt = $this->conn->prepare($sql);
      $stmt->execute([$user_id, $zona_user]);
      $event = $stmt->fetch(PDO::FETCH_ASSOC);

      // 5. Format boolean response for attendance check
      if ($event) {
        $event['isAttended'] = (bool) $event['isAttended'];
        return $this->response(200, "success", "Dashboard event retrieved successfully.", $event);
      } else {
        return $this->response(200, "success", "No live or upcoming events found.", null);
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Database Error: " . $e->getMessage());
    }
  }

  // GET EVENTS
  /**
   * Fetch all event schedules (upcoming, live, and completed) targeted for the user
   *
   * @param array $data Request payload containing user_id
   * @return void
   */
  public function getevents($data)
  {
    // 1. Retrieve user_id from request body or query parameter
    $user_id = $data['user_id'] ?? $_GET['user_id'] ?? '';

    // 2. Validate required user_id
    if (empty($user_id)) {
      return $this->response(400, "failed", "User ID is invalid or missing.");
    }

    try {
      // Synchronize event statuses before fetching data
      $this->syncEventStatuses();

      // 3. Get targeted user's zone
      $stmt_user = $this->conn->prepare("SELECT zona FROM users WHERE id = ?");
      $stmt_user->execute([$user_id]);
      $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
      $zona_user = $user['zona'] ?? '';

      // 4. Query all event schedules (live, upcoming, and completed) matching user's zone or general events
      $sql = "
                SELECT e.id,
                       e.nama_event AS eventName,
                       e.deskripsi_event AS description,
                       e.tempat AS location,
                       e.pembicara AS speaker,
                       e.waktu_event AS timeStart,
                       e.waktu_selesai AS timeEnd, 
                       e.status, 
                       e.jenis_event AS category, 
                       e.zona_target AS zone,
                       e.qr_hash AS qrHash, 
                       e.latitude, 
                       e.longitude, 
                       e.radius,
                       (SELECT COUNT(id) FROM attendances WHERE event_id = e.id AND user_id = ?) as isAttended
                FROM events e
                WHERE (e.jenis_event = 'jamaah' OR e.zona_target = ?)
                ORDER BY 
                  FIELD(e.status, 'live', 'mendatang', 'selesai'), 
                  e.waktu_event ASC, 
                  e.id ASC
            ";

      $stmt = $this->conn->prepare($sql);
      $stmt->execute([$user_id, $zona_user]);
      $jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // 5. Format boolean response for attendance check across array items
      $formattedJadwal = array_map(function ($event) {
        $event['isAttended'] = (bool) $event['isAttended'];
        return $event;
      }, $jadwal);

      return $this->response(200, "success", "Schedule list retrieved successfully.", $formattedJadwal);

    } catch (Exception $e) {
      return $this->response(500, "error", "Database Error: " . $e->getMessage());
    }
  }

  // GET MY ATTENDANCE
  /**
   * Return the logged-in congregant's attendance history for completed events.
   *
   * Present rows come from `attendances`; absent rows are derived from the
   * completed events that fall within the congregant's scope.
   *
   * @param array $data Request payload (optional user_id must match session)
   * @return void
   */
  public function getMyAttendance($data)
  {
    try {
      $authUser = getAuthenticatedUser($this->conn);
    } catch (Exception $e) {
      error_log("Failed to resolve attendance session: " . $e->getMessage());
      return $this->response(200, "failed", "Gagal memvalidasi sesi login. Silakan coba lagi.");
    }

    // The contract requires application failures to arrive in the JSON body.
    if (!$authUser) {
      return $this->response(200, "failed", "Sesi login tidak valid, silakan login kembali.");
    }

    if (($authUser['role'] ?? '') !== 'user') {
      return $this->response(200, "failed", "Endpoint ini hanya dapat diakses oleh jamaah.");
    }

    $claimedUserId = $data['user_id'] ?? null;
    if ($claimedUserId !== null && $claimedUserId !== ''
      && (string) $claimedUserId !== (string) $authUser['id']) {
      return $this->response(200, "failed", "Anda hanya dapat melihat riwayat absensi milik sendiri.");
    }

    try {
      // Keep event statuses and check-in times in WIB consistent for this request.
      $this->conn->exec("SET time_zone = '+07:00'");
      $this->syncEventStatuses();

      $sql = "
        SELECT
          a.id                                   AS attendance_id,
          e.id                                   AS event_id,
          e.nama_event                           AS event_name,
          DATE_FORMAT(e.waktu_event, '%Y-%m-%d') AS event_date,
          TIME_FORMAT(a.waktu_absen, '%H:%i')    AS check_in_time
        FROM events e
        JOIN users u ON u.id = :user_id
        LEFT JOIN attendances a
          ON a.event_id = e.id AND a.user_id = u.id
        WHERE e.status = 'selesai'
          AND e.waktu_event IS NOT NULL
          AND (
            e.jenis_event = 'jamaah'
            OR e.zona_target = u.zona
            OR a.id IS NOT NULL
          )
        ORDER BY e.waktu_event DESC, e.id DESC
      ";

      $stmt = $this->conn->prepare($sql);
      $stmt->execute([':user_id' => $authUser['id']]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $records = array_map(function ($row) {
        $hasAttended = $row['attendance_id'] !== null;
        $eventId = (string) $row['event_id'];
        $eventName = trim((string) ($row['event_name'] ?? ''));

        return [
          'id' => $hasAttended ? (int) $row['attendance_id'] : 'abs-' . $eventId,
          'eventId' => $eventId,
          'eventName' => $eventName !== '' ? $eventName : 'Kegiatan Tanpa Judul',
          'eventDate' => (string) $row['event_date'],
          'checkInTime' => $hasAttended ? $row['check_in_time'] : null,
          'status' => $hasAttended ? 'hadir' : 'absen',
        ];
      }, $rows);

      return $this->response(200, "success", "Riwayat absensi berhasil diambil.", $records);

    } catch (Exception $e) {
      error_log("Failed to fetch attendance history: " . $e->getMessage());
      return $this->response(200, "failed", "Gagal memuat riwayat absensi. Silakan coba lagi.");
    }
  }

  // ATTENDANCES
  /**
   * Summary of haversineDistance
   * @param float $latitudeFrom
   * @param float $longitudeFrom
   * @param float $latitudeTo
   * @param float $longitudeTo
   * @return float
   */
  private function haversineDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
  {
    $earthRadius = 6371000; // Earth radius in meters

    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
      cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

    return $angle * $earthRadius;
  }

  // ATTENDANCES
  /**
   * Summary of submitAttendance
   * @param mixed $data
   * @return void
   */
  public function submitAttendance($data)
  {
    if (
      empty($data['userId']) ||
      empty($data['qrHash']) ||
      !isset($data['latitude']) ||
      !isset($data['longitude'])
    ) {
      $this->response(400, "error", "Data tidak lengkap. 'userId', 'qrHash', dan koordinat lokasi wajib diisi.");
    }

    $userId = $data['userId'];
    $qrHash = trim($data['qrHash']);
    $userLat = (float) $data['latitude'];
    $userLng = (float) $data['longitude'];

    try {
      // Find event by qr_hash
      $stmtEvent = $this->conn->prepare("
        SELECT id, nama_event, status, latitude, longitude, radius 
        FROM events 
        WHERE qr_hash = :qrHash 
        LIMIT 1
      ");
      $stmtEvent->execute([':qrHash' => $qrHash]);
      $event = $stmtEvent->fetch(PDO::FETCH_ASSOC);

      if (!$event) {
        $this->response(404, "error", "Kode QR tidak valid atau acara tidak ditemukan.");
      }

      // Check event status
      if ($event['status'] !== 'live') {
        $this->response(400, "error", "Tidak dapat absen. Acara belum dimulai atau telah selesai.");
      }

      // Validate geofencing location radius
      if (!is_null($event['latitude']) && !is_null($event['longitude'])) {
        $distance = $this->haversineDistance(
          $userLat,
          $userLng,
          (float) $event['latitude'],
          (float) $event['longitude']
        );

        $allowedRadius = (int) ($event['radius'] ?? 100);

        if ($distance > $allowedRadius) {
          $this->response(400, "error", "Tidak dapat absen, Anda berada di luar radius lokasi acara ( " . round($distance) . " m dari lokasi ).");
        }
      }

      // Insert attendance record
      $stmtInsert = $this->conn->prepare("
        INSERT INTO attendances (user_id, event_id, waktu_absen) 
        VALUES (:user_id, :event_id, NOW())
      ");

      $stmtInsert->execute([
        ':user_id' => $userId,
        ':event_id' => $event['id']
      ]);

      $this->response(200, "success", "Presensi berhasil dicatat!", [
        "event_name" => $event['nama_event'],
        "waktu_absen" => date('Y-m-d H:i:s')
      ]);

    } catch (PDOException $e) {
      if ($e->getCode() == '23000') {
        $this->response(409, "error", "Anda sudah melakukan absensi pada acara ini.");
      }

      $this->response(500, "error", "Terjadi kesalahan pada server: " . $e->getMessage());
    }
  }

  // SEARCH HADITH
  /**
   * Search hadith from Ahmad Sanusi API securely using cURL
   * 
   * @param array $data Request payload containing query, kitab, page, limit
   * @return void
   */
  public function searchHadith($data)
  {
    // GET PARAMETERS FROM GET OR PAYLOAD
    $keyword = $data['q'] ?? $_GET['q'] ?? '';
    $kitab = $_GET['kitab'] ?? $data['kitab'] ?? '';
    $page = isset($_GET['page']) ? intval($_GET['page']) : ($data['page'] ?? 1);
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : ($data['limit'] ?? 5);

    // BACKEND VALIDATION HANDLER
    $trimmedKeyword = trim($keyword);
    $isNumericQuery = is_numeric($trimmedKeyword);

    // ALLOW NUMERIC INPUT OR MINIMUM 3 CHARACTERS FOR TEXT
    if (strlen($trimmedKeyword) < 3 && !$isNumericQuery) {
      return $this->response(400, "failed", "Kata kunci pencarian minimal harus 3 karakter atau masukkan nomor hadis.");
    }

    // BUILD QUERY PARAMETERS FOR EXTERNAL API
    $queryParams = [
      'q' => $keyword,
      'page' => $page,
      'limit' => $limit
    ];

    if (!empty($kitab)) {
      $queryParams['kitab'] = $kitab;
    }

    $targetUrl = AHMAD_SANUSI_BASE_URL . '/hadits/search?' . http_build_query($queryParams);

    try {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $targetUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key:' . AHMAD_SANUSI_API_KEY,
        'Accept: application/json'
      ]);

      $response = curl_exec($ch);
      $curlError = curl_error($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      if ($curlError) {
        return $this->response(500, "error", "Curl Error: " . $curlError);
      }

      $responseData = json_decode($response, true);

      // IF HTTP SUCCESS AND QUERY IS NUMERIC, FILTER RESULTS BY EXACT HADITH NUMBER
      if ($httpCode >= 200 && $httpCode < 300 && isset($responseData['data']['results'])) {
        if ($isNumericQuery) {
          $targetNumber = intval($trimmedKeyword);

          // Filter results where hadith number matches exactly
          $filteredResults = array_values(array_filter($responseData['data']['results'], function ($item) use ($targetNumber) {
            return isset($item['nomor']) && intval($item['nomor']) === $targetNumber;
          }));

          // Update response data with filtered results
          $responseData['data']['results'] = $filteredResults;
          $responseData['data']['total'] = count($filteredResults);
        }

        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo json_encode($responseData);
        exit;
      } else {
        return $this->response($httpCode, "failed", "External API error.", $responseData);
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Internal Server Error: " . $e->getMessage());
    }
  }

  // GET HADITH BOOKS
  /**
   * Retrieve all available hadith books from Ahmad Sanusi API securely
   * 
   * @param array $data Request payload
   * @return void
   */
  public function getHadithBooks($data)
  {
    $targetUrl = AHMAD_SANUSI_BASE_URL . '/hadits';

    try {
      // INITIALIZE CURL TO FETCH BOOKS
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $targetUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_USERAGENT, 'HadithAppBackend/1.0');
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key:' . AHMAD_SANUSI_API_KEY,
        'Accept: application/json'
      ]);

      $response = curl_exec($ch);
      $curlError = curl_error($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      unset($ch);

      // HANDLE CURL CONNECTION ERROR
      if ($curlError) {
        return $this->response(500, "error", "Curl Error: " . $curlError);
      }

      $responseData = json_decode($response, true);

      // RETURN RESPONSE TO FRONTEND
      if ($httpCode >= 200 && $httpCode < 300) {
        return $this->response(200, "success", "Hadith books retrieved successfully.", $responseData);
      } else {
        return $this->response($httpCode, "failed", "External API error.", $responseData);
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Internal Server Error: " . $e->getMessage());
    }
  }

  // GET HADITH BY KITAB
  /**
   * Fetch a list of hadiths by kitab slug with pagination.
   *
   * @param array $data Input data containing optional 'kitab', 'page', and 'limit' parameters.
   * @return mixed JSON response output containing hadiths list and pagination info, or response payload.
   */
  public function getHadithsByKitab($data = [])
  {
    // GET PARAMETERS FROM GET OR PAYLOAD
    // fetch request inputs with fallback default values
    $kitab = $_GET['kitab'] ?? $data['kitab'] ?? '';
    $page = isset($_GET['page']) ? intval($_GET['page']) : ($data['page'] ?? 1);
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : ($data['limit'] ?? 5);

    // BACKEND VALIDATION HANDLER
    // validate required kitab slug parameter
    if (empty(trim($kitab))) {
      return $this->response(400, "failed", "Parameter kitab wajib diisi.");
    }

    // ensure pagination bounds stay within valid range
    $page = max(1, $page);
    $limit = min(200, max(1, $limit));

    // BUILD QUERY PARAMETERS FOR EXTERNAL API
    // construct query parameters array
    $queryParams = [
      'page' => $page,
      'limit' => $limit
    ];

    // build full endpoint target url with path parameter
    $targetUrl = AHMAD_SANUSI_BASE_URL . '/hadits/' . urlencode($kitab) . '?' . http_build_query($queryParams);

    try {
      // EXECUTE CURL REQUEST
      // initialize curl session
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $targetUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_USERAGENT, 'HadithAppBackend/1.0');
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key:' . AHMAD_SANUSI_API_KEY,
        'Accept: application/json'
      ]);

      $response = curl_exec($ch);
      $curlError = curl_error($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      unset($ch);

      // handle curl connection errors
      if ($curlError) {
        return $this->response(500, "error", "Curl Error: " . $curlError);
      }

      $responseData = json_decode($response, true);

      // HANDLE API RESPONSE
      // process HTTP status code response
      if ($httpCode >= 200 && $httpCode < 300) {
        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo json_encode($responseData);
        exit;
      } else {
        return $this->response($httpCode, "failed", "External API error.", $responseData);
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Internal Server Error: " . $e->getMessage());
    }
  }

  // SEARCH DOA
  /**
   * Search doa from external API securely using cURL
   * 
   * @param array $data Request payload containing q and limit
   * @return void
   */
  public function searchDoa($data)
  {
    // GET PARAMETERS FROM GET OR PAYLOAD
    $keyword = $data['q'] ?? $_GET['q'] ?? '';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : ($data['limit'] ?? 20);

    // BACKEND VALIDATION HANDLER
    $trimmedKeyword = trim($keyword);

    // ALLOW MINIMUM 2 CHARACTERS ACCORDING TO API SPECIFICATION
    if (strlen($trimmedKeyword) < 2) {
      return $this->response(400, "failed", "Kata kunci pencarian minimal harus 2 karakter.");
    }

    // ENSURE LIMIT STAYS WITHIN PERMITTED BOUNDS (1 - 5)
    $limit = max(1, min(5, $limit));

    // BUILD QUERY PARAMETERS FOR EXTERNAL API
    $queryParams = [
      'q' => $trimmedKeyword,
      'limit' => $limit
    ];

    $targetUrl = AHMAD_SANUSI_BASE_URL . '/doa/search?' . http_build_query($queryParams);

    try {
      // INITIALIZE CURL REQUEST
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $targetUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_USERAGENT, 'HadithAppBackend/1.0');
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key:' . AHMAD_SANUSI_API_KEY,
        'Accept: application/json'
      ]);

      $response = curl_exec($ch);
      $curlError = curl_error($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      // HANDLE CURL EXECUTION ERROR
      if ($curlError) {
        return $this->response(500, "error", "Curl Error: " . $curlError);
      }

      $responseData = json_decode($response, true);

      // RETURN JSON RESPONSE ON SUCCESS
      if ($httpCode >= 200 && $httpCode < 300) {
        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo json_encode($responseData);
        exit;
      } else {
        return $this->response($httpCode, "failed", "External API error.", $responseData);
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Internal Server Error: " . $e->getMessage());
    }
  }

  // GET DOA CATEGORIES
  /**
   * Retrieve all available doa and dzikir categories from external API securely
   * 
   * @param array $data Request payload
   * @return void
   */
  public function getDoaCategories($data)
  {
    // target endpoint for doa categories
    $targetUrl = AHMAD_SANUSI_BASE_URL . '/doa/kategori';

    try {
      // initialize curl to fetch categories
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $targetUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_USERAGENT, 'HadithAppBackend/1.0');
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key:' . AHMAD_SANUSI_API_KEY,
        'Accept: application/json'
      ]);

      $response = curl_exec($ch);
      $curlError = curl_error($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      // handle curl connection error
      if ($curlError) {
        return $this->response(500, "error", "Curl Error: " . $curlError);
      }

      $responseData = json_decode($response, true);

      // return json response to frontend
      if ($httpCode >= 200 && $httpCode < 300) {
        return $this->response(200, "success", "Doa categories retrieved successfully.", $responseData);
      } else {
        return $this->response($httpCode, "failed", "External API error.", $responseData);
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Internal Server Error: " . $e->getMessage());
    }
  }

  // GET DOA BY CATEGORY
  /**
   * Fetch a list of doas by category slug with pagination.
   *
   * @param array $data Input data containing optional 'slug', 'page', and 'limit' parameters.
   * @return mixed JSON response output containing doas list and pagination info, or response payload.
   */
  public function getDoaByCategory($data = [])
  {
    // GET PARAMETERS FROM GET OR PAYLOAD
    // fetch request inputs with fallback default values
    $slug = $_GET['slug'] ?? $data['slug'] ?? '';
    $page = isset($_GET['page']) ? intval($_GET['page']) : ($data['page'] ?? 1);
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : ($data['limit'] ?? 10);

    // BACKEND VALIDATION HANDLER
    // validate required slug parameter
    if (empty(trim($slug))) {
      return $this->response(400, "failed", "Parameter slug kategori wajib diisi.");
    }

    // ensure pagination bounds stay within valid range (limit max 100)
    $page = max(1, $page);
    $limit = min(100, max(1, $limit));

    // BUILD QUERY PARAMETERS FOR EXTERNAL API
    // construct query parameters array
    $queryParams = [
      'page' => $page,
      'limit' => $limit
    ];

    // build full endpoint target url with path parameter
    $targetUrl = AHMAD_SANUSI_BASE_URL . '/doa/kategori/' . urlencode(trim($slug)) . '?' . http_build_query($queryParams);

    try {
      // EXECUTE CURL REQUEST
      // initialize curl session
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $targetUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_USERAGENT, 'HadithAppBackend/1.0');
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key:' . AHMAD_SANUSI_API_KEY,
        'Accept: application/json'
      ]);

      $response = curl_exec($ch);
      $curlError = curl_error($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      unset($ch);

      // handle curl connection errors
      if ($curlError) {
        return $this->response(500, "error", "Curl Error: " . $curlError);
      }

      $responseData = json_decode($response, true);

      // HANDLE API RESPONSE
      // process HTTP status code response
      if ($httpCode >= 200 && $httpCode < 300) {
        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo json_encode($responseData);
        exit;
      } else {
        return $this->response($httpCode, "failed", "External API error.", $responseData);
      }

    } catch (Exception $e) {
      return $this->response(500, "error", "Internal Server Error: " . $e->getMessage());
    }
  }
}
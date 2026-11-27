<?php

/**
 * Attendance recap service shared by the admin and leader endpoints.
 *
 * AdminController and LeaderController are largely duplicated in this project.
 * The recap queries are complex enough that duplicating them twice would be a
 * maintenance hazard, so the logic lives here and each controller only
 * resolves its own role before delegating.
 *
 * Rules implemented here (docs/api-contract-admin-leader-attendance.md):
 * - The caller is resolved from the PHP session; body `zone` / `leader_id` /
 *   `user_id` never change the authenticated identity or widen the scope.
 * - Absent rows are derived at query time from
 *   `completed events x jamaah in scope LEFT JOIN attendances`. The legacy
 *   `attendances` table only stores successful scans, so no schema change is
 *   needed to count absences.
 * - Every application failure is HTTP 200 with status "failed" and an
 *   Indonesian, user-facing message.
 */

require_once __DIR__ . '/../api/auth.php';

class AttendanceRecapService
{
  private $conn;

  /** Default and maximum page size for every paginated block. */
  private const PER_PAGE_DEFAULT = 10;
  private const PER_PAGE_MAX = 100;

  /** Indonesian month labels used by the derived filter options. */
  private const MONTH_LABELS = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
  ];

  public function __construct($dbConnection)
  {
    $this->conn = $dbConnection;
  }

  // =========================================================================
  // PUBLIC ACTIONS
  // =========================================================================

  /**
   * Return completed events with scoped attendance statistics.
   *
   * @param array  $data         Request payload
   * @param string $expectedRole 'admin' or 'leader'
   * @return void
   */
  public function getAttendanceRecap($data, $expectedRole)
  {
    $scope = $this->resolveScope($expectedRole);

    try {
      $this->prepareSession();

      $payload = $this->buildAttendanceRecap($scope, is_array($data) ? $data : []);

      $this->respond('success', 'Rekap kehadiran berhasil diambil.', $payload);
    } catch (Throwable $e) {
      error_log('Failed to load attendance recap: ' . $e->getMessage());
      $this->respond('error', 'Gagal memuat rekap kehadiran.');
    }
  }

  /**
   * Return one completed event with participant statistics and the scoped
   * participant list.
   *
   * @param array  $data         Request payload
   * @param string $expectedRole 'admin' or 'leader'
   * @return void
   */
  public function getEventAttendanceDetail($data, $expectedRole)
  {
    $scope = $this->resolveScope($expectedRole);

    try {
      $this->prepareSession();

      $payload = $this->buildEventAttendanceDetail($scope, is_array($data) ? $data : []);

      $this->respond('success', 'Detail kehadiran kegiatan berhasil diambil.', $payload);
    } catch (Throwable $e) {
      error_log('Failed to load event attendance detail: ' . $e->getMessage());
      $this->respond('error', 'Gagal memuat detail kehadiran kegiatan.');
    }
  }

  /**
   * Return one jamaah's identity, all-time statistics, and event history.
   * `user_id` is only the target record; the caller's identity always comes
   * from the session.
   *
   * @param array  $data         Request payload
   * @param string $expectedRole 'admin' or 'leader'
   * @return void
   */
  public function getJamaahAttendanceHistory($data, $expectedRole)
  {
    $scope = $this->resolveScope($expectedRole);

    try {
      $this->prepareSession();

      $payload = $this->buildJamaahAttendanceHistory($scope, is_array($data) ? $data : []);

      $this->respond('success', 'Riwayat kehadiran jamaah berhasil diambil.', $payload);
    } catch (Throwable $e) {
      error_log('Failed to load jamaah attendance history: ' . $e->getMessage());
      $this->respond('error', 'Gagal memuat riwayat kehadiran jamaah.');
    }
  }

  // =========================================================================
  // SESSION / RESPONSE HELPERS
  // =========================================================================

  /**
   * Private helper to return a JSON response. Attendance endpoints always use
   * HTTP 200, including failures, so the frontend can render the Indonesian
   * message instead of a generic axios error.
   */
  private function respond($statusText, $message, $data = null)
  {
    http_response_code(200);

    $payload = [
      'status' => $statusText,
      'message' => $message,
    ];

    if ($data !== null) {
      $payload['data'] = $data;
    }

    echo json_encode($payload);
    exit();
  }

  /**
   * Resolve the logged-in user from the session and enforce the expected role.
   * Role and zone are refreshed from the database by getAuthenticatedUser(),
   * so stale session data cannot grant access.
   *
   * @param string $expectedRole
   * @return array{id:int,role:string,zone:?string}
   */
  private function resolveScope($expectedRole)
  {
    try {
      $authUser = getAuthenticatedUser($this->conn);
    } catch (Throwable $e) {
      error_log('Failed to resolve attendance session: ' . $e->getMessage());
      $this->respond('failed', 'Gagal memvalidasi sesi login. Silakan coba lagi.');
    }

    if (!$authUser) {
      $this->respond('failed', 'Sesi login tidak valid, silakan login kembali.');
    }

    if (($authUser['role'] ?? '') !== $expectedRole) {
      $this->respond('failed', 'Anda tidak memiliki akses ke halaman ini.');
    }

    $zone = trim((string) ($authUser['zona'] ?? ''));
    $zone = $zone === '' ? null : strtoupper($zone);

    if ($expectedRole === 'leader' && $zone === null) {
      $this->respond('failed', 'Zona belum ditetapkan. Silakan hubungi admin.');
    }

    return [
      'id' => (int) $authUser['id'],
      'role' => $expectedRole,
      'zone' => $zone,
    ];
  }

  /**
   * Keep event statuses and check-in times consistent for this request:
   * WIB timestamps and freshly derived `selesai` events.
   */
  private function prepareSession()
  {
    $this->conn->exec("SET time_zone = '+07:00'");
    $this->syncEventStatuses();
  }

  /**
   * Synchronize event statuses based on the current WIB time. Mirrors the
   * helper in AdminController / LeaderController; keep the three in sync.
   */
  private function syncEventStatuses()
  {
    try {
      $this->conn->exec("
        UPDATE events
        SET status = 'selesai'
        WHERE status != 'selesai'
          AND waktu_selesai <= NOW()
      ");

      $this->conn->exec("
        UPDATE events
        SET status = 'live'
        WHERE status = 'mendatang'
          AND NOW() BETWEEN waktu_event AND waktu_selesai
      ");
    } catch (Throwable $e) {
      // Log silently so a status sync failure does not break the recap.
      error_log('Failed to sync event statuses: ' . $e->getMessage());
    }
  }

  // =========================================================================
  // get_attendance_recap
  // =========================================================================

  /**
   * Build the recap payload: global stats, one page of completed events,
   * pagination, the resolved scope, and derived filter options.
   */
  private function buildAttendanceRecap($scope, $data)
  {
    // 1. NORMALISE REQUEST PARAMETERS
    [$page, $perPage] = $this->normalizePagination($data);

    $search = trim((string) ($data['search'] ?? ''));
    $month = $this->normalizeMonth($data['month'] ?? 'all');

    $year = (string) ($data['year'] ?? 'all');
    if (!preg_match('/^\d{4}$/', $year)) {
      $year = 'all';
    }

    // `zone` is an admin-only filter. The leader scope always comes from the
    // session and is never widened by the request body.
    $zoneFilter = $scope['role'] === 'admin'
      ? $this->normalizeZoneFilter($data['zone'] ?? 'all')
      : null;

    // 2. COMPLETE SCOPED EVENT SET (ignores search / month / year / zone)
    [$scopeSql, $scopeParams] = $this->eventScope($scope);

    $stmt = $this->conn->prepare(
      "SELECT e.id, e.jenis_event, e.zona_target, e.waktu_event
       FROM events e
       WHERE $scopeSql"
    );
    $stmt->execute($scopeParams);
    $scopedEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. JAMAHA COUNTS AND PRESENT COUNTS FOR THE WHOLE SCOPED SET
    [$totalJamaah, $jamaahByZone] = $this->jamaahCounts();

    $scopedIds = array_map(static function ($event) {
      return (int) $event['id'];
    }, $scopedEvents);

    $presentByEvent = $this->presentCounts($scopedIds, $scope);

    $totalPresent = 0;
    $totalParticipants = 0;

    foreach ($scopedEvents as $event) {
      $present = $presentByEvent[(int) $event['id']] ?? 0;
      $participants = $this->participantsForEvent(
        $event,
        $scope,
        $totalJamaah,
        $jamaahByZone
      );

      $totalPresent += $present;
      $totalParticipants += $participants;
    }

    $totalAbsent = max(0, $totalParticipants - $totalPresent);

    $stats = [
      'totalEvents' => count($scopedEvents),
      'totalPresent' => $totalPresent,
      'totalAbsent' => $totalAbsent,
      'percentage' => $this->percentage($totalPresent, $totalParticipants),
    ];

    // 4. DERIVED FILTER OPTIONS (complete scoped dataset, ignoring filters)
    $filters = [
      'months' => $this->monthOptions($scopedEvents),
      'years' => $this->yearOptions($scopedEvents),
      'zones' => $scope['role'] === 'admin' ? $this->zoneOptions($scopedEvents) : [],
    ];

    // 5. FILTERED EVENT PAGE
    [$pageWhere, $pageParams] = $this->recapFilterClause(
      $scope,
      $scopeSql,
      $scopeParams,
      $search,
      $month,
      $year,
      $zoneFilter
    );

    $countStmt = $this->conn->prepare("SELECT COUNT(*) FROM events e WHERE $pageWhere");
    $countStmt->execute($pageParams);
    $total = (int) $countStmt->fetchColumn();

    [$pagination, $offset] = $this->paginationFor($page, $perPage, $total);

    $pageSql = "SELECT
                  e.id,
                  e.nama_event,
                  e.tempat,
                  e.waktu_event,
                  e.waktu_selesai,
                  e.jenis_event,
                  e.zona_target,
                  e.status
                FROM events e
                WHERE $pageWhere
                ORDER BY e.waktu_event DESC, e.id DESC
                LIMIT $perPage OFFSET $offset";

    $pageStmt = $this->conn->prepare($pageSql);
    $pageStmt->execute($pageParams);
    $eventRows = $pageStmt->fetchAll(PDO::FETCH_ASSOC);

    $pageIds = array_map(static function ($row) {
      return (int) $row['id'];
    }, $eventRows);

    // Admin zone filter also narrows the row counts (contract decision: the
    // selected zone affects the list, while global stats stay unfiltered).
    $pagePresentByEvent = $this->presentCounts($pageIds, $scope, $zoneFilter);

    $events = [];
    foreach ($eventRows as $row) {
      $eventId = (int) $row['id'];
      $present = $pagePresentByEvent[$eventId] ?? 0;

      $participants = $this->participantsForEvent(
        $row,
        $scope,
        $totalJamaah,
        $jamaahByZone,
        $zoneFilter
      );

      $absent = max(0, $participants - $present);

      $events[] = [
        'eventId' => $eventId,
        'id' => $eventId,
        'eventName' => trim((string) ($row['nama_event'] ?? '')),
        'eventDate' => $this->eventDate($row['waktu_event']),
        'timeStart' => $row['waktu_event'],
        'timeEnd' => $row['waktu_selesai'],
        'location' => $row['tempat'],
        'zone' => $row['zona_target'],
        'status' => $row['status'],
        'totalPresent' => $present,
        'totalAbsent' => $absent,
        'totalParticipants' => $present + $absent,
        'percentage' => $this->percentage($present, $present + $absent),
      ];
    }

    // 6. PAGINATION BLOCK
    return [
      'stats' => $stats,
      'events' => $events,
      'pagination' => $pagination,
      'scope' => [
        'role' => $scope['role'],
        'zone' => $scope['role'] === 'leader' ? $scope['zone'] : null,
        'isZoneLocked' => $scope['role'] === 'leader',
      ],
      'filters' => $filters,
    ];
  }

  /**
   * Event scope shared by every recap query. Only completed events are
   * eligible; a leader additionally sees all-zone events plus their own zone.
   *
   * @return array{0:string,1:array<string,mixed>}
   */
  private function eventScope($scope)
  {
    $where = ["e.status = 'selesai'", 'e.waktu_event IS NOT NULL'];
    $params = [];

    if ($scope['role'] === 'leader') {
      $where[] = "(e.jenis_event = 'jamaah' OR e.zona_target IS NULL OR e.zona_target = 'umum' OR e.zona_target = :scope_zone)";
      $params[':scope_zone'] = $scope['zone'];
    }

    return [implode(' AND ', $where), $params];
  }

  /**
   * Apply the request filters on top of the scoped event set.
   *
   * @return array{0:string,1:array<string,mixed>}
   */
  private function recapFilterClause($scope, $scopeSql, $scopeParams, $search, $month, $year, $zoneFilter)
  {
    $where = [$scopeSql];
    $params = $scopeParams;

    if ($search !== '') {
      $where[] = 'e.nama_event LIKE :search';
      $params[':search'] = $this->likePattern($search);
    }

    if ($month !== 'all') {
      $where[] = 'MONTH(e.waktu_event) = :month';
      $params[':month'] = (int) $month;
    }

    if ($year !== 'all') {
      $where[] = 'YEAR(e.waktu_event) = :year';
      $params[':year'] = (int) $year;
    }

    if ($scope['role'] === 'admin' && $zoneFilter !== null) {
      // All-zone (jamaah) events stay in the list; their counts are narrowed.
      $where[] = "(e.jenis_event = 'jamaah' OR e.zona_target IS NULL OR e.zona_target = 'umum' OR e.zona_target = :filter_zone)";
      $params[':filter_zone'] = $zoneFilter;
    }

    return [implode(' AND ', $where), $params];
  }

  // =========================================================================
  // get_event_attendance_detail
  // =========================================================================

  /**
   * Build the event detail payload: event header, participant statistics, and
   * one page of participants.
   */
  private function buildEventAttendanceDetail($scope, $data)
  {
    // 1. VALIDATE THE REQUESTED EVENT
    $eventId = (int) ($data['event_id'] ?? 0);

    if ($eventId <= 0) {
      $this->respond('failed', 'Kegiatan tidak ditemukan.');
    }

    $stmt = $this->conn->prepare(
      "SELECT id, nama_event, tempat, waktu_event, waktu_selesai, jenis_event, zona_target, status
       FROM events
       WHERE id = ?
       LIMIT 1"
    );
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
      $this->respond('failed', 'Kegiatan tidak ditemukan.');
    }

    if (($event['status'] ?? '') !== 'selesai') {
      $this->respond('failed', 'Rekap hanya tersedia untuk kegiatan yang telah selesai.');
    }

    // 2. ENFORCE THE LEADER ZONE ON THE EVENT ITSELF
    $eventZone = strtoupper(trim((string) ($event['zona_target'] ?? '')));
    $isJamaahEvent = ($event['jenis_event'] ?? '') === 'jamaah';
    $isAllZoneEvent = $isJamaahEvent || $eventZone === '' || $eventZone === 'UMUM';

    if ($scope['role'] === 'leader' && !$isAllZoneEvent && $eventZone !== $scope['zone']) {
      $this->respond('failed', 'Anda tidak memiliki akses ke kegiatan di luar zona Anda.');
    }

    // 3. NORMALISE FILTERS
    [$page, $perPage] = $this->normalizePagination($data);

    $search = trim((string) ($data['search'] ?? ''));
    $status = $this->normalizeStatusFilter($data['status'] ?? 'all');

    // `zone` is an admin-only participant filter. Leaders are always scoped to
    // their session zone, so any client-supplied zone is ignored.
    $zoneFilter = $scope['role'] === 'admin'
      ? $this->normalizeZoneFilter($data['zone'] ?? 'all')
      : null;

    // Participant scope: leader session zone, admin filter zone, or the event's
    // own zone. `null` means every jamaah in the event scope.
    $participantZone = null;
    $zoneMismatch = false;

    if ($scope['role'] === 'leader') {
      $participantZone = $scope['zone'];
    } elseif (!$isAllZoneEvent) {
      if ($zoneFilter !== null && $zoneFilter !== $eventZone) {
        // The selected zone has no jamaah in this zone-targeted event.
        $zoneMismatch = true;
      } else {
        $participantZone = $eventZone;
      }
    } else {
      $participantZone = $zoneFilter;
    }

    // 4. STATISTICS FOR THE WHOLE SCOPED PARTICIPANT SET
    // Contract §6.2: stats ignore search, status, and the admin zone filter.
    [$totalJamaah, $jamaahByZone] = $this->jamaahCounts();

    $statsParticipants = $this->participantsForEvent(
      $event,
      $scope,
      $totalJamaah,
      $jamaahByZone
    );

    $presentMap = $this->presentCounts([$eventId], $scope);
    $statsPresent = $presentMap[$eventId] ?? 0;
    $statsAbsent = max(0, $statsParticipants - $statsPresent);

    $stats = [
      'totalParticipants' => $statsParticipants,
      'totalPresent' => $statsPresent,
      'totalAbsent' => $statsAbsent,
      'percentage' => $this->percentage($statsPresent, $statsParticipants),
    ];

    // 5. FILTERED PARTICIPANT PAGE
    $where = ["u.role = 'user'"];
    $params = [':event_id' => $eventId];

    if ($participantZone !== null) {
      $where[] = 'u.zona = :participant_zone';
      $params[':participant_zone'] = $participantZone;
    }

    if ($search !== '') {
      $where[] = 'u.nama_lengkap LIKE :search';
      $params[':search'] = $this->likePattern($search);
    }

    if ($status === 'hadir') {
      $where[] = 'a.id IS NOT NULL';
    } elseif ($status === 'absen') {
      $where[] = 'a.id IS NULL';
    }

    $whereSql = implode(' AND ', $where);
    $fromSql = "FROM users u
                LEFT JOIN attendances a
                  ON a.user_id = u.id AND a.event_id = :event_id
                WHERE $whereSql";

    $total = 0;
    $participants = [];

    if (!$zoneMismatch) {
      $countStmt = $this->conn->prepare("SELECT COUNT(*) $fromSql");
      $countStmt->execute($params);
      $total = (int) $countStmt->fetchColumn();
    }

    [$pagination, $offset] = $this->paginationFor($page, $perPage, $total);

    if (!$zoneMismatch && $total > 0) {
      $pageSql = "SELECT
                    u.id,
                    u.nama_lengkap,
                    u.zona,
                    a.id AS attendance_id,
                    TIME_FORMAT(a.waktu_absen, '%H:%i') AS check_in_time
                  $fromSql
                  ORDER BY u.nama_lengkap ASC, u.id ASC
                  LIMIT $perPage OFFSET $offset";

      $pageStmt = $this->conn->prepare($pageSql);
      $pageStmt->execute($params);

      foreach ($pageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hasAttended = $row['attendance_id'] !== null;
        $zone = strtoupper(trim((string) ($row['zona'] ?? '')));

        $participants[] = [
          'userId' => (int) $row['id'],
          'fullName' => trim((string) ($row['nama_lengkap'] ?? '')),
          'regionId' => null,
          'regionName' => $zone !== '' ? 'Wilayah ' . $zone : null,
          'zone' => $zone !== '' ? $zone : null,
          'checkInTime' => $hasAttended ? $row['check_in_time'] : null,
          'status' => $hasAttended ? 'hadir' : 'absen',
        ];
      }
    }

    return [
      'event' => [
        'eventId' => (int) $event['id'],
        'id' => (int) $event['id'],
        'eventName' => trim((string) ($event['nama_event'] ?? '')),
        'eventDate' => $this->eventDate($event['waktu_event']),
        'timeStart' => $event['waktu_event'],
        'timeEnd' => $event['waktu_selesai'],
        'location' => $event['tempat'],
        'zone' => $event['zona_target'],
        'status' => $event['status'],
      ],
      'stats' => $stats,
      'participants' => $participants,
      'pagination' => $pagination,
    ];
  }

  // =========================================================================
  // get_jamaah_attendance_history
  // =========================================================================

  /**
   * Build the jamaah history payload: identity, all-time statistics, and one
   * page of event history rows.
   */
  private function buildJamaahAttendanceHistory($scope, $data)
  {
    // 1. VALIDATE THE TARGET JAMAHA. `user_id` is only the target record; the
    //    caller's identity always comes from the session.
    $targetUserId = (int) ($data['user_id'] ?? 0);

    if ($targetUserId <= 0) {
      $this->respond('failed', 'Data jamaah tidak ditemukan.');
    }

    $stmt = $this->conn->prepare(
      "SELECT id, nama_lengkap, zona, nomor_porsi, gambar
       FROM users
       WHERE id = ? AND role = 'user'
       LIMIT 1"
    );
    $stmt->execute([$targetUserId]);
    $jamaah = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$jamaah) {
      $this->respond('failed', 'Data jamaah tidak ditemukan.');
    }

    // 2. LEADERS MAY ONLY READ JAMAHA IN THEIR OWN CURRENT ZONE
    $jamaahZone = strtoupper(trim((string) ($jamaah['zona'] ?? '')));

    if ($scope['role'] === 'leader' && $jamaahZone !== $scope['zone']) {
      $this->respond('failed', 'Anda tidak memiliki akses ke data jamaah di luar zona Anda.');
    }

    // 3. NORMALISE FILTERS
    [$page, $perPage] = $this->normalizePagination($data);

    $search = trim((string) ($data['search'] ?? ''));
    $month = $this->normalizeMonth($data['month'] ?? 'all');
    $status = $this->normalizeStatusFilter($data['status'] ?? 'all');

    // 4. EVENT SCOPE FOR THIS JAMAHA (mirrors user.php get_my_attendance)
    $fromSql = "FROM events e
                LEFT JOIN attendances a
                  ON a.event_id = e.id AND a.user_id = :target_user_id";

    $historyWhere = "e.status = 'selesai'
                     AND e.waktu_event IS NOT NULL
                     AND (e.jenis_event = 'jamaah' OR e.zona_target = :jamaah_zone OR a.id IS NOT NULL)";

    $historyParams = [
      ':target_user_id' => $targetUserId,
      ':jamaah_zone' => $jamaahZone !== '' ? $jamaahZone : null,
    ];

    // 5. ALL-TIME STATISTICS AND MONTH OPTIONS (ignore active filters)
    $statsStmt = $this->conn->prepare(
      "SELECT e.id, e.waktu_event, a.id AS attendance_id
       $fromSql
       WHERE $historyWhere
       ORDER BY e.waktu_event DESC, e.id DESC"
    );
    $statsStmt->execute($historyParams);
    $allRows = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRecords = count($allRows);
    $totalPresent = 0;

    foreach ($allRows as $row) {
      if ($row['attendance_id'] !== null) {
        $totalPresent++;
      }
    }

    $totalAbsent = max(0, $totalRecords - $totalPresent);

    $stats = [
      'totalPresent' => $totalPresent,
      'totalAbsent' => $totalAbsent,
      'totalRecords' => $totalRecords,
      'percentage' => $this->percentage($totalPresent, $totalRecords),
    ];

    $filters = ['months' => $this->monthOptions($allRows)];

    // 6. FILTERED HISTORY PAGE
    $where = [$historyWhere];
    $params = $historyParams;

    if ($search !== '') {
      $where[] = 'e.nama_event LIKE :search';
      $params[':search'] = $this->likePattern($search);
    }

    if ($month !== 'all') {
      $where[] = 'MONTH(e.waktu_event) = :month';
      $params[':month'] = (int) $month;
    }

    if ($status === 'hadir') {
      $where[] = 'a.id IS NOT NULL';
    } elseif ($status === 'absen') {
      $where[] = 'a.id IS NULL';
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $this->conn->prepare("SELECT COUNT(*) $fromSql WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    [$pagination, $offset] = $this->paginationFor($page, $perPage, $total);

    $records = [];

    if ($total > 0) {
      $pageSql = "SELECT
                    e.id AS event_id,
                    e.nama_event,
                    e.waktu_event,
                    a.id AS attendance_id,
                    TIME_FORMAT(a.waktu_absen, '%H:%i') AS check_in_time
                  $fromSql
                  WHERE $whereSql
                  ORDER BY e.waktu_event DESC, e.id DESC
                  LIMIT $perPage OFFSET $offset";

      $pageStmt = $this->conn->prepare($pageSql);
      $pageStmt->execute($params);

      foreach ($pageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hasAttended = $row['attendance_id'] !== null;
        $eventId = (int) $row['event_id'];

        $records[] = [
          'id' => 'history-' . $eventId . '-' . $targetUserId,
          'eventId' => $eventId,
          'eventName' => trim((string) ($row['nama_event'] ?? '')),
          'eventDate' => $this->eventDate($row['waktu_event']),
          'checkInTime' => $hasAttended ? $row['check_in_time'] : null,
          'status' => $hasAttended ? 'hadir' : 'absen',
        ];
      }
    }

    // identity fields come straight from users; absent columns stay null so the
    // frontend can render placeholders without branching on undefined keys.
    return [
      'jamaah' => [
        'userId' => (int) $jamaah['id'],
        'fullName' => trim((string) ($jamaah['nama_lengkap'] ?? '')),
        'photoUrl' => $jamaah['gambar'] ?? null,
        'zone' => $jamaahZone !== '' ? $jamaahZone : null,
        'regionName' => $jamaahZone !== '' ? 'Wilayah ' . $jamaahZone : null,
        'portionNumber' => $jamaah['nomor_porsi'] ?? null,
      ],
      'stats' => $stats,
      'records' => $records,
      'pagination' => $pagination,
      'filters' => $filters,
    ];
  }

  // =========================================================================
  // DATA HELPERS
  // =========================================================================

  /**
   * Normalise `page` / `per_page`: 1-based, default 10, capped at 100.
   *
   * @return array{0:int,1:int}
   */
  private function normalizePagination($data)
  {
    $page = max(1, (int) ($data['page'] ?? 1));

    $perPage = (int) ($data['per_page'] ?? self::PER_PAGE_DEFAULT);
    if ($perPage < 1) {
      $perPage = self::PER_PAGE_DEFAULT;
    }

    return [$page, min($perPage, self::PER_PAGE_MAX)];
  }

  /**
   * Clamp the requested page to the result set and build the pagination block.
   *
   * @return array{0:array<string,mixed>,1:int} [pagination block, SQL offset]
   */
  private function paginationFor($page, $perPage, $total)
  {
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    return [[
      'page' => $page,
      'perPage' => $perPage,
      'total' => $total,
      'totalPages' => $totalPages,
      'from' => $total > 0 ? $offset + 1 : 0,
      'to' => $total > 0 ? min($offset + $perPage, $total) : 0,
      'hasNextPage' => $page < $totalPages,
      'hasPreviousPage' => $page > 1,
    ], $offset];
  }

  /**
   * Normalise the `month` filter: "all" or "01"–"12".
   */
  private function normalizeMonth($value)
  {
    $month = (string) $value;

    return preg_match('/^(0[1-9]|1[0-2])$/', $month) ? $month : 'all';
  }

  /**
   * Normalise the `status` filter: "all", "hadir", or "absen".
   */
  private function normalizeStatusFilter($value)
  {
    $status = strtolower(trim((string) $value));

    return in_array($status, ['hadir', 'absen'], true) ? $status : 'all';
  }

  /**
   * Normalise an admin `zone` filter: null (all zones) or "A"–"F".
   */
  private function normalizeZoneFilter($value)
  {
    $zone = strtoupper(trim((string) $value));

    return in_array($zone, ['A', 'B', 'C', 'D', 'E', 'F'], true) ? $zone : null;
  }

  /**
   * Count jamaah per zone and in total. `users.role = 'user'` is the
   * denominator for every attendance percentage.
   *
   * @return array{0:int,1:array<string,int>}
   */
  private function jamaahCounts()
  {
    $stmt = $this->conn->query(
      "SELECT zona, COUNT(*) AS total
       FROM users
       WHERE role = 'user'
       GROUP BY zona"
    );

    $total = 0;
    $byZone = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $count = (int) $row['total'];
      $total += $count;

      $zone = strtoupper(trim((string) ($row['zona'] ?? '')));
      if ($zone !== '') {
        $byZone[$zone] = $count;
      }
    }

    return [$total, $byZone];
  }

  /**
   * Present counts grouped by event for the given event ids.
   *
   * A scan only counts when the jamaah belongs to the event's participant
   * scope (all-zone events accept everyone, zone-targeted events accept only
   * that zone). Leaders are always restricted to their session zone; an
   * explicit $onlyZone narrows the admin list to the selected filter zone.
   *
   * @return array<int,int>
   */
  private function presentCounts(array $eventIds, $scope, $onlyZone = null)
  {
    if (empty($eventIds)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $params = array_map('intval', $eventIds);

    $sql = "SELECT a.event_id, COUNT(*) AS present
            FROM attendances a
            JOIN users u ON u.id = a.user_id AND u.role = 'user'
            JOIN events e ON e.id = a.event_id
            WHERE a.event_id IN ($placeholders)
              AND (e.jenis_event = 'jamaah' OR e.zona_target IS NULL OR e.zona_target = 'umum' OR u.zona = e.zona_target)";

    $zoneForCounts = $scope['role'] === 'leader' ? $scope['zone'] : $onlyZone;
    if ($zoneForCounts !== null) {
      $sql .= ' AND u.zona = ?';
      $params[] = $zoneForCounts;
    }

    $sql .= ' GROUP BY a.event_id';

    $stmt = $this->conn->prepare($sql);
    $stmt->execute($params);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $map[(int) $row['event_id']] = (int) $row['present'];
    }

    return $map;
  }

  /**
   * Number of jamaah included in an event's scope.
   *
   * - leader: always their session zone, even for all-zone events.
   * - admin + filter zone: that zone (row counts follow the filter).
   * - admin: jamaah events (jenis_event = 'jamaah') and all-zone events cover
   *   every jamaah; zone-targeted leader events cover that zone only.
   */
  private function participantsForEvent($event, $scope, $totalJamaah, $jamaahByZone, $zoneOverride = null)
  {
    if ($zoneOverride !== null) {
      return $jamaahByZone[$zoneOverride] ?? 0;
    }

    if ($scope['role'] === 'leader') {
      return $jamaahByZone[$scope['zone']] ?? 0;
    }

    $isJamaahEvent = ($event['jenis_event'] ?? '') === 'jamaah';
    $zone = strtoupper(trim((string) ($event['zona_target'] ?? '')));

    if ($isJamaahEvent || $zone === '' || $zone === 'UMUM') {
      return $totalJamaah;
    }

    return $jamaahByZone[$zone] ?? 0;
  }

  /**
   * Shared rounding formula: round(present / total * 100), 0 when empty.
   */
  private function percentage($present, $total)
  {
    if ($total <= 0) {
      return 0;
    }

    return (int) round($present / $total * 100);
  }

  /**
   * Escape LIKE wildcards so user input is treated as literal text.
   */
  private function likePattern($value)
  {
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

    return '%' . $escaped . '%';
  }

  private function eventDate($waktuEvent)
  {
    if (empty($waktuEvent)) {
      return null;
    }

    return substr((string) $waktuEvent, 0, 10);
  }

  /**
   * Month options, newest first, de-duplicated across years.
   */
  private function monthOptions(array $events)
  {
    usort($events, static function ($a, $b) {
      return strcmp((string) ($b['waktu_event'] ?? ''), (string) ($a['waktu_event'] ?? ''));
    });

    $options = [['value' => 'all', 'label' => 'Semua Bulan']];
    $seen = [];

    foreach ($events as $event) {
      $timestamp = strtotime((string) ($event['waktu_event'] ?? ''));
      if ($timestamp === false) {
        continue;
      }

      $month = (int) date('n', $timestamp);
      if (isset($seen[$month])) {
        continue;
      }

      $seen[$month] = true;
      $options[] = [
        'value' => str_pad((string) $month, 2, '0', STR_PAD_LEFT),
        'label' => self::MONTH_LABELS[$month],
      ];
    }

    return $options;
  }

  /**
   * Year options, newest first.
   */
  private function yearOptions(array $events)
  {
    $years = [];

    foreach ($events as $event) {
      $timestamp = strtotime((string) ($event['waktu_event'] ?? ''));
      if ($timestamp === false) {
        continue;
      }

      $years[(int) date('Y', $timestamp)] = true;
    }

    $years = array_keys($years);
    rsort($years);

    $options = [['value' => 'all', 'label' => 'Semua Tahun']];
    foreach ($years as $year) {
      $options[] = ['value' => (string) $year, 'label' => (string) $year];
    }

    return $options;
  }

  /**
   * Zone options from the completed events in scope (admin only).
   */
  private function zoneOptions(array $events)
  {
    $zones = [];

    foreach ($events as $event) {
      $zone = strtoupper(trim((string) ($event['zona_target'] ?? '')));
      if (($event['jenis_event'] ?? '') === 'leader'
        && in_array($zone, ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
        $zones[$zone] = true;
      }
    }

    $zones = array_keys($zones);
    sort($zones);

    $options = [['value' => 'all', 'label' => 'Semua Zona']];
    foreach ($zones as $zone) {
      $options[] = ['value' => $zone, 'label' => 'Zona ' . $zone];
    }

    return $options;
  }
}

<?php
/**
 * /forms/learning/list.php
 * Версия: v1.8.2 (2025-12-10)
 * - Добавлен столбец «Текущий исполнитель» (исполнители БП)
 * - DOCUMENT_ID формата: ['lists', 'BizprocDocument', IBLOCK_ID, ELEMENT_ID]
 * - Исполнители БП выводятся слева от «Статус»
 */

use Bitrix\Main\Loader;

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Заявки на обучение");

if (!Loader::includeModule("iblock")) {
    ShowError("Не удалось подключить модуль iblock");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

$IBLOCK_ID      = 382;
$GROUP_ID       = 225;
$IBLOCK_STATUS  = 383; // справочник статусов

// Карта свойств по ID
$PROP_MAP = [
    3000 => ['code' => 'FIO_SOTRUDNKIA',      'title' => 'ФИО сотрудника'],
    3001 => ['code' => 'GOROD_SOTRUDNIKA',    'title' => 'Город сотрудника'],
    3002 => ['code' => 'TEMA_OBUCHENIYA',     'title' => 'Тема обучения'],
    3004 => ['code' => 'DATA_NACHALA',        'title' => 'Дата начала'],
    3005 => ['code' => 'DATA_OKONCHANIYA',    'title' => 'Дата окончания'],
    3008 => ['code' => 'STATUS',              'title' => 'Статус'],
    3009 => ['code' => 'ISTORIYA',            'title' => 'История заявки'],
    3010 => ['code' => 'TIP_OBUCHENIYA',       'title' => 'Тип обучения'],
    3062 => ['code' => 'GOROD_OBUCHENIYA_WANT','title' => 'Город обучения (желаемый)'],
    3017 => ['code' => 'GOROD_OBUCHENIYA',    'title' => 'Город обучения'],
    3016 => ['code' => 'DATA_NACHALA_OBUCHENIYA', 'title' => 'Дата начала обучения'],
    3019 => ['code' => 'DATA_OKONCHANIYA_OBUCHENIYA', 'title' => 'Дата окончания обучения'],
];

// helpers
function h($s){ return htmlspecialcharsbx((string)$s); }

function qs(array $params, array $keep = [])
{
    $result = [];
    foreach ($_GET as $k => $v) {
        if (in_array($k, $keep, true)) {
            $result[$k] = $v;
        }
    }
    foreach ($params as $k => $v) {
        $result[$k] = $v;
    }
    return http_build_query($result);
}

function userNameById($userId)
{
    $userId = normalizeUserId($userId);
    if ($userId <= 0) return '';
    $rsUser = CUser::GetByID($userId);
    if ($arUser = $rsUser->Fetch()) {
        $name = trim($arUser["LAST_NAME"]." ".$arUser["NAME"]." ".$arUser["SECOND_NAME"]);
        return $name ?: $arUser["LOGIN"];
    }
    return '';
}

function normalizeUserId($value): int
{
    if (is_array($value)) $value = reset($value);
    if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
    return preg_match('/(\d+)\s*$/', (string)$value, $matches) ? (int)$matches[1] : 0;
}

function statusInfoById($statusId, $iblockStatus)
{
    static $cache = [];
    $statusId = (int)$statusId;
    if ($statusId <= 0) {
        return ['NAME' => '', 'COLOR' => '#ccc'];
    }
    if (isset($cache[$statusId])) {
        return $cache[$statusId];
    }
    $res = CIBlockElement::GetList(
        [],
        ["IBLOCK_ID" => $iblockStatus, "ID" => $statusId, "ACTIVE" => "Y"],
        false,
        false,
        ["ID", "NAME", "PROPERTY_COLOR"]
    );
    if ($ar = $res->Fetch()) {
        $color = $ar["PROPERTY_COLOR_VALUE"] ?: "#17a2b8";
        return $cache[$statusId] = [
            "NAME"  => $ar["NAME"],
            "COLOR" => $color,
        ];
    }
    return $cache[$statusId] = ['NAME' => '', 'COLOR' => '#ccc'];
}

function propValueSafe(array $props, int $iblockId, int $elementId, int $propId, string $propCode)
{
    if (isset($props[$propCode])) { $v = $props[$propCode]["VALUE"]; return is_array($v) ? implode(", ", $v) : $v; }
    if (isset($props[$propId]))   { $v = $props[$propId]["VALUE"];   return is_array($v) ? implode(", ", $v) : $v; }
    $res = CIBlockElement::GetProperty($iblockId, $elementId, [], ["ID"=>$propId]);
    $vals = [];
    while ($ar = $res->Fetch()) { if ($ar["VALUE"] !== null && $ar["VALUE"] !== "") $vals[] = $ar["VALUE"]; }
    return $vals ? (count($vals)>1 ? implode(", ", $vals) : $vals[0]) : '';
}

function trainingTypeOptions(int $propertyId): array
{
    $result = [];
    $res = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
    while ($item = $res->Fetch()) {
        $result[(int)$item['ID']] = (string)$item['VALUE'];
    }
    return $result;
}

function listPropertyValueSafe(
    array $props,
    int $iblockId,
    int $elementId,
    int $propId,
    string $propCode,
    array $options
): string {
    $property = $props[$propCode] ?? $props[$propId] ?? null;
    if (is_array($property)) {
        $enumValues = $property['VALUE_ENUM'] ?? null;
        if ($enumValues !== null && $enumValues !== '' && $enumValues !== []) {
            return is_array($enumValues) ? implode(', ', $enumValues) : (string)$enumValues;
        }

        $values = (array)($property['VALUE'] ?? []);
        $labels = [];
        foreach ($values as $value) {
            if ($value !== null && $value !== '') $labels[] = $options[(int)$value] ?? (string)$value;
        }
        if ($labels) return implode(', ', $labels);
    }

    $labels = [];
    $res = CIBlockElement::GetProperty($iblockId, $elementId, [], ['ID'=>$propId]);
    while ($item = $res->Fetch()) {
        if (!empty($item['VALUE_ENUM'])) {
            $labels[] = (string)$item['VALUE_ENUM'];
        } elseif ($item['VALUE'] !== null && $item['VALUE'] !== '') {
            $labels[] = $options[(int)$item['VALUE']] ?? (string)$item['VALUE'];
        }
    }
    return implode(', ', $labels);
}

function orgDepartmentById(int $departmentId): ?array
{
    static $cache = [];
    if ($departmentId <= 0) return null;
    if (array_key_exists($departmentId, $cache)) return $cache[$departmentId];

    $res = CIBlockSection::GetList(
        [],
        ['ID'=>$departmentId],
        false,
        ['ID','NAME','IBLOCK_SECTION_ID','UF_HEAD']
    );
    $department = $res->Fetch();
    return $cache[$departmentId] = ($department ?: null);
}

function orgStructureManagerId(array $departmentIds, int $employeeId): int
{
    foreach ($departmentIds as $departmentId) {
        $visited = [];
        while ($departmentId > 0 && empty($visited[$departmentId])) {
            $visited[$departmentId] = true;
            $department = orgDepartmentById($departmentId);
            if (!$department) break;

            $head = $department['UF_HEAD'] ?? 0;
            if (is_array($head)) $head = reset($head);
            $headId = (int)$head;
            if ($headId > 0 && $headId !== $employeeId) return $headId;

            // Если сотрудник сам возглавляет подразделение или руководитель не
            // указан, продолжаем поиск вверх по оргструктуре портала.
            $departmentId = (int)($department['IBLOCK_SECTION_ID'] ?? 0);
        }
    }
    return 0;
}

function portalManagerId(array $user): int
{
    if (!CModule::IncludeModule('intranet')) return 0;

    $departments = array_filter(array_map('intval', (array)($user['UF_DEPARTMENT'] ?? [])));
    if (!$departments) return 0;

    // Штатный метод Bitrix учитывает оргструктуру портала и поднимается по ней
    // при поиске непосредственного руководителя сотрудника.
    $managers = CIntranetUtils::GetDepartmentManager($departments, (int)$user['ID'], true);
    if (is_array($managers)) {
        foreach ($managers as $manager) {
            $managerId = normalizeUserId(is_array($manager) ? ($manager['ID'] ?? 0) : $manager);
            if ($managerId > 0 && $managerId !== (int)$user['ID']) return $managerId;
        }
    }
    return 0;
}

function exportUserData($userId): array
{
    static $cache = [];
    $userId = normalizeUserId($userId);
    if ($userId <= 0) return ['fio'=>'', 'position'=>'', 'department'=>'', 'email'=>'', 'manager'=>''];
    if (isset($cache[$userId])) return $cache[$userId];

    $by = 'id';
    $order = 'asc';
    $dbUser = CUser::GetList(
        $by,
        $order,
        ['ID_EQUAL_EXACT'=>$userId],
        ['FIELDS'=>['ID','LOGIN','NAME','LAST_NAME','SECOND_NAME','EMAIL','WORK_POSITION'], 'SELECT'=>['UF_*']]
    );
    $user = $dbUser->Fetch();
    if (!$user) return $cache[$userId] = ['fio'=>'', 'position'=>'', 'department'=>'', 'email'=>'', 'manager'=>''];

    $departmentIds = array_filter(array_map('intval', (array)($user['UF_DEPARTMENT'] ?? [])));
    $departmentNames = [];
    foreach ($departmentIds as $departmentId) {
        $department = orgDepartmentById($departmentId);
        if ($department) $departmentNames[] = (string)$department['NAME'];
    }

    $managerId = portalManagerId($user);
    if ($managerId <= 0) $managerId = orgStructureManagerId($departmentIds, $userId);
    $managerName = $managerId > 0 ? userNameById($managerId) : '';

    return $cache[$userId] = [
        'fio' => userNameById($userId),
        'position' => (string)($user['WORK_POSITION'] ?? ''),
        'department' => implode(', ', $departmentNames),
        'email' => (string)($user['EMAIL'] ?? ''),
        'manager' => $managerName,
    ];
}

function excelCell($value): string
{
    return '<td>'.htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
}

/* ------------------------- Бизнес-процессы ------------------------- */

function getCurrentBpUsers(int $elementId): array
{
    if (!\Bitrix\Main\Loader::includeModule("bizproc")) {
        return [];
    }

    global $IBLOCK_ID;

    $userIds = [];

    // Кандидаты DOCUMENT_ID — взято из рабочего примера
    $candidates = [
        ["lists", "Bitrix\\Lists\\BizprocDocumentLists", (string)$elementId],
        ["lists", "BizprocDocument", "lists_{$IBLOCK_ID}_{$elementId}"],
        ["lists", "lists_{$IBLOCK_ID}_group_{$GLOBALS['GROUP_ID']}", (int)$elementId],
        ["lists", "lists_{$IBLOCK_ID}", (int)$elementId],
        ["iblock", "CIBlockDocument", "iblock_{$IBLOCK_ID}_{$elementId}"],
    ];

    foreach ($candidates as $docIdCandidate) {

        $rs = CBPTaskService::GetList(
            ["ID" => "DESC"],
            [
                "DOCUMENT_ID" => $docIdCandidate,
                "STATUS"      => 0, // активные задачи
            ],
            false,
            false,
            ["USER_ID"]
        );

        while ($task = $rs->GetNext()) {
            $uid = (int)$task["USER_ID"];
            if ($uid > 0) {
                $userIds[$uid] = true;
            }
        }
    }

    return array_keys($userIds);
}


function renderUserList(array $ids): string {
    if (empty($ids)) {
        return '<span class="text-muted">—</span>';
    }

    $result = [];
    $rs = CUser::GetList("", "", ["ID" => implode("|", $ids)], [
        "FIELDS" => ["ID","NAME","LAST_NAME","SECOND_NAME"]
    ]);

    while ($u = $rs->Fetch()) {
        $fio = trim($u["LAST_NAME"]." ".$u["NAME"]);
        $result[] = htmlspecialcharsbx($fio ?: $u["LOGIN"]);
    }

    return $result ? implode("<br>", $result) : '<span class="text-muted">—</span>';
}

/* ------------------------- сортировка ------------------------- */

$allowedSort = [
    'id'        => 'ID',
    'employee'  => 'PROPERTY_3000',
    'city'      => 'PROPERTY_3017',
    'topic'     => 'PROPERTY_3002',
    'start'     => 'PROPERTY_3016',
    'end'       => 'PROPERTY_3019',
    'status'    => 'PROPERTY_3008',
    'type'      => 'PROPERTY_3010',
];

$sortKey = isset($_GET['sort'], $allowedSort[$_GET['sort']]) ? $_GET['sort'] : 'id';
$dir     = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';
$order = [$allowedSort[$sortKey] => $dir, 'ID' => 'DESC'];

/* ------------------------- фильтры ------------------------- */

global $USER;
$userId = (int)$USER->GetID();
if ($userId <= 0) {
    ShowError("Требуется авторизация");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

$filter = [
    "IBLOCK_ID"         => $IBLOCK_ID,
    "ACTIVE"            => "Y",
    "CHECK_PERMISSIONS" => "Y",
];

$q = trim((string)($_GET['q'] ?? ''));
$trainingTypes = trainingTypeOptions(3010);
$trainingTypeFilter = (int)($_GET['training_type'] ?? 0);
if ($trainingTypeFilter > 0 && isset($trainingTypes[$trainingTypeFilter])) {
    $filter['PROPERTY_3010'] = $trainingTypeFilter;
}
if ($q !== '') {
    $filter[] = [
        "LOGIC" => "OR",
        "%NAME"            => $q,
        "%PROPERTY_3062"   => $q,
        "%PROPERTY_3017"   => $q,
        "%PROPERTY_3002"   => $q,
    ];
}

/* ------------------------- выборка ------------------------- */

$arSelect = ["ID", "NAME"];

$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';
$rsItems = CIBlockElement::GetList(
    $order,
    $filter,
    false,
    $isExport ? false : ["nPageSize" => 50],
    $arSelect
);

if ($isExport) {
    $APPLICATION->RestartBuffer();
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="learning_requests_'.date('Y-m-d').'.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body><table border="1"><thead><tr>';
    foreach (['ID','Тип обучения','Тема обучения','Город обучения','Дата начала','Дата окончания','ФИО сотрудника','Должность','Подразделение','Адрес эл. почты','ФИО руководителя','Статус'] as $heading) echo excelCell($heading);
    echo '</tr></thead><tbody>';
    while ($ob = $rsItems->GetNextElement()) {
        $fields = $ob->GetFields();
        $props = $ob->GetProperties();
        $elementId = (int)$fields['ID'];
        $employeeId = normalizeUserId(propValueSafe($props, $IBLOCK_ID, $elementId, 3000, $PROP_MAP[3000]['code']));
        $userData = exportUserData($employeeId);
        $type = listPropertyValueSafe($props, $IBLOCK_ID, $elementId, 3010, $PROP_MAP[3010]['code'], $trainingTypes);
        $topic = propValueSafe($props, $IBLOCK_ID, $elementId, 3002, $PROP_MAP[3002]['code']);
        $city = propValueSafe($props, $IBLOCK_ID, $elementId, 3017, $PROP_MAP[3017]['code']) ?: propValueSafe($props, $IBLOCK_ID, $elementId, 3062, $PROP_MAP[3062]['code']);
        $start = propValueSafe($props, $IBLOCK_ID, $elementId, 3016, $PROP_MAP[3016]['code']) ?: propValueSafe($props, $IBLOCK_ID, $elementId, 3004, $PROP_MAP[3004]['code']);
        $end = propValueSafe($props, $IBLOCK_ID, $elementId, 3019, $PROP_MAP[3019]['code']) ?: propValueSafe($props, $IBLOCK_ID, $elementId, 3005, $PROP_MAP[3005]['code']);
        $statusId = propValueSafe($props, $IBLOCK_ID, $elementId, 3008, $PROP_MAP[3008]['code']);
        $status = statusInfoById($statusId, $IBLOCK_STATUS)['NAME'];
        echo '<tr>'.excelCell($elementId).excelCell($type).excelCell($topic).excelCell($city).excelCell($start).excelCell($end)
            .excelCell($userData['fio']).excelCell($userData['position']).excelCell($userData['department'])
            .excelCell($userData['email']).excelCell($userData['manager']).excelCell($status).'</tr>';
    }
    echo '</tbody></table></body></html>';
    die();
}
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

<style>
  .page-wrap { padding: 16px 24px; }
  .table thead th { white-space: nowrap; }
  .sort-link { color: #fff; text-decoration: none; }
  .sort-link:hover { text-decoration: underline; }
  .sort-caret { font-weight: 700; margin-left: 4px; }
  .status-history-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; margin-left: 6px; border-radius: 50%;
    border: 1px solid rgba(0,0,0,.2); font-size: 11px; background: #fff;
    color: #333; cursor: pointer;
  }
  .status-history-icon:hover { background: #f1f1f1; }
  .history-modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    display: none; align-items: center; justify-content: center; z-index: 9999;
  }
  .history-modal {
    background: #fff; border-radius: 8px; max-width: 820px; width: 90%;
    max-height: 80vh; box-shadow: 0 10px 30px rgba(0,0,0,.25);
    display: flex; flex-direction: column; overflow: hidden;
  }
  .history-modal-header { padding: 12px 16px; border-bottom: 1px solid #e5e5e5; display:flex; justify-content:space-between; }
  .history-modal-title { font-size: 16px; font-weight: 600; }
  .history-modal-body { padding: 12px 16px 16px; overflow-y: auto; }
  body.history-modal-open { overflow:hidden; }
</style>

<div class="container-fluid page-wrap">
  <h2 class="mb-3">Заявки на обучение</h2>
  <p class="mb-3">Список ваших заявок на обучение. Актуальный статус согласования отображается в поле «Статус».</p>

  <div class="d-flex align-items-center mb-3 flex-wrap">
    <a href="/forms/learning/create_request.php" class="btn btn-success mr-3">Создать новую заявку</a>

    <form method="get" class="form-inline">
      <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
      <input type="hidden" name="dir"  value="<?= h(strtolower($dir)) ?>">
      <input type="text" name="q" value="<?= h($q) ?>" class="form-control mr-2" placeholder="Поиск по ФИО, городу, теме">
      <select name="training_type" class="form-control mr-2">
        <option value="">Все типы обучения</option>
        <?php foreach ($trainingTypes as $typeId => $typeName): ?>
          <option value="<?= (int)$typeId ?>" <?= $trainingTypeFilter === (int)$typeId ? 'selected' : '' ?>><?= h($typeName) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary mr-2">Найти</button>
      <a href="<?= h($APPLICATION->GetCurPage()) ?>" class="btn btn-secondary">Сброс</a>
    </form>
    <a href="?<?= h(qs(['export'=>'excel'], ['q','training_type','sort','dir'])) ?>" class="btn btn-outline-success ml-2">Выгрузить в Excel</a>
  </div>

<?php if ($rsItems->SelectedRowsCount() <= 0): ?>
  <div class="alert alert-info">Нет доступных заявок на обучение.</div>

<?php else: ?>

<?php
  $dirOpposite = ($dir === 'ASC') ? 'DESC' : 'ASC';
  $makeSortLink = function(string $key, string $title) use ($sortKey, $dir, $dirOpposite) {
    $isActive = ($sortKey === $key);
    $url = '?'.qs(['sort' => $key, 'dir' => $isActive ? $dirOpposite : 'ASC'], ['q','training_type']);
    $caret = '';
    if ($isActive) $caret = $dir === 'ASC' ? '▲' : '▼';
    return '<a href="'.h($url).'" class="sort-link">'.h($title).($caret ? '<span class="sort-caret">'.$caret.'</span>' : '').'</a>';
  };
?>

<div class="table-responsive">
  <table class="table table-sm table-bordered table-hover">
    <thead class="thead-dark">
      <tr>
        <th><?= $makeSortLink('id',       'ID') ?></th>
        <th><?= $makeSortLink('employee', 'ФИО сотрудника') ?></th>
        <th><?= $makeSortLink('type',     'Тип обучения') ?></th>
        <th><?= $makeSortLink('city',     'Город обучения') ?></th>
        <th><?= $makeSortLink('topic',    'Тема обучения') ?></th>
        <th><?= $makeSortLink('start',    'Дата начала') ?></th>
        <th><?= $makeSortLink('end',      'Дата окончания') ?></th>
        <th>Текущий исполнитель</th>
        <th><?= $makeSortLink('status',   'Статус') ?></th>
        <th>Открыть</th>
      </tr>
    </thead>
    <tbody>

<?php while ($ob = $rsItems->GetNextElement()):
    $f = $ob->GetFields();
    $p = $ob->GetProperties();

    $v3000 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3000, $PROP_MAP[3000]['code']);
    $v3002 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3002, $PROP_MAP[3002]['code']);
    $v3008 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3008, $PROP_MAP[3008]['code']);
    $v3009 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3009, $PROP_MAP[3009]['code']);
    $v3010 = listPropertyValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3010, $PROP_MAP[3010]['code'], $trainingTypes);
    $v3017 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3017, $PROP_MAP[3017]['code']);
    $v3062 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3062, $PROP_MAP[3062]['code']);
    $v3016 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3016, $PROP_MAP[3016]['code']);
    $v3019 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3019, $PROP_MAP[3019]['code']);
    $v3004 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3004, $PROP_MAP[3004]['code']);
    $v3005 = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3005, $PROP_MAP[3005]['code']);

    $employeeId = normalizeUserId($v3000);
    $employeeName = $employeeId > 0 ? userNameById($employeeId) : '';
    $cityToShow = $v3017 ?: $v3062;
    $dateStartToShow = $v3016 ?: $v3004;
    $dateEndToShow   = $v3019 ?: $v3005;
    $statusInfo = $v3008 ? statusInfoById($v3008, $IBLOCK_STATUS) : ['NAME'=>'','COLOR'=>'#ccc'];

    /* История */
    $historyHtml = '';
    if ($v3009) {
        $lines = preg_split("/\r\n|\n|\r/u", trim((string)$v3009));
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})\s+(.*)$/u', $line, $m)) {
                $items[] = ['datetime' => $m[1], 'text' => $m[2]];
            } else {
                $items[] = ['datetime' => '', 'text' => $line];
            }
        }
        if ($items) {
            $historyHtml .= '<ul class="list-unstyled history-list mb-0">';
            foreach ($items as $it) {
                $dt = $it['datetime'] ? '<div class="history-item-datetime">'.h($it['datetime']).'</div>' : '';
                $text = '<div class="history-item-text">'.h($it['text']).'</div>';
                $historyHtml .= '<li class="history-item mb-2">'.$dt.$text.'</li>';
            }
            $historyHtml .= '</ul>';
        }
    }

    $openUrl = "/forms/learning/view.php?id=".(int)$f['ID'];
?>
<tr>
  <td><?= (int)$f['ID'] ?></td>
  <td><?= h($employeeName) ?></td>
  <td><?= h($v3010) ?></td>
  <td><?= h($cityToShow) ?></td>
  <td><?= h($v3002) ?></td>
  <td><?= h($dateStartToShow) ?></td>
  <td><?= h($dateEndToShow) ?></td>

  <!-- Новый столбец: Текущий исполнитель -->
  <td><?= renderUserList( getCurrentBpUsers((int)$f['ID']) ) ?></td>

  <td>
    <?php if ($statusInfo['NAME']): ?>
      <span class="badge" style="background:<?= h($statusInfo['COLOR']) ?>; color:#fff;">
        <?= h($statusInfo['NAME']) ?>
      </span>

      <?php if ($historyHtml): ?>
      <button type="button"
              class="status-history-icon js-history-icon"
              data-history-id="history-<?= (int)$f['ID'] ?>"
              title="Показать историю заявки">i</button>
      <div id="history-<?= (int)$f['ID'] ?>" class="d-none">
        <?= $historyHtml ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </td>

  <td><a href="<?= h($openUrl) ?>" target="_blank" rel="noopener">Открыть</a></td>
</tr>
<?php endwhile; ?>

    </tbody>
  </table>
</div>

<?php endif; ?>
</div>

<!-- Модальное окно истории -->
<div id="history-modal-backdrop" class="history-modal-backdrop">
  <div class="history-modal">
    <div class="history-modal-header">
      <div class="history-modal-title">История заявки</div>
      <button type="button" class="history-modal-close js-history-close">&times;</button>
    </div>
    <div class="history-modal-body" id="history-modal-body"></div>
  </div>
</div>

<script>
(function() {
  var backdrop = document.getElementById('history-modal-backdrop');
  if (!backdrop) return;
  var bodyEl = document.getElementById('history-modal-body');

  function openHistory(html) {
    bodyEl.innerHTML = html;
    backdrop.style.display = 'flex';
    document.body.classList.add('history-modal-open');
  }
  function closeHistory() {
    backdrop.style.display = 'none';
    document.body.classList.remove('history-modal-open');
    bodyEl.innerHTML = '';
  }

  backdrop.addEventListener('click', function(e) {
    if (e.target === backdrop || e.target.closest('.js-history-close')) {
      closeHistory();
    }
  });

  document.addEventListener('click', function(e) {
    var icon = e.target.closest ? e.target.closest('.js-history-icon') : null;
    if (!icon) return;
    var id = icon.getAttribute('data-history-id');
    var container = document.getElementById(id);
    if (container) openHistory(container.innerHTML);
  });
})();
</script>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>

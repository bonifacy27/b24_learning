<?php
/**
 * /forms/learning/view.php
 * Просмотр заполненных полей заявки на обучение.
 */

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');

const VIEW_TRAINING_IBLOCK_ID = 382;
const VIEW_EMPLOYEE_PROPERTY_ID = 3000;

global $APPLICATION, $USER;
$APPLICATION->SetTitle('Просмотр заявки на обучение');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

if (!(int)$USER->GetID()) {
    ShowError('Требуется авторизация.');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

function viewH($value): string
{
    return htmlspecialcharsbx((string)$value);
}

function viewNormalizeUserId($value): int
{
    if (is_array($value)) $value = reset($value);
    if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
    return preg_match('/(\d+)\s*$/', (string)$value, $matches) ? (int)$matches[1] : 0;
}

function viewUserName($value): string
{
    static $cache = [];
    $userId = viewNormalizeUserId($value);
    if ($userId <= 0) return '';
    if (isset($cache[$userId])) return $cache[$userId];

    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) return $cache[$userId] = '';
    $name = trim(($user['LAST_NAME'] ?? '').' '.($user['NAME'] ?? '').' '.($user['SECOND_NAME'] ?? ''));
    return $cache[$userId] = ($name ?: (string)($user['LOGIN'] ?? ''));
}

function viewElementName($value): string
{
    static $cache = [];
    $elementId = (int)$value;
    if ($elementId <= 0) return '';
    if (isset($cache[$elementId])) return $cache[$elementId];
    $element = CIBlockElement::GetByID($elementId)->Fetch();
    return $cache[$elementId] = ($element ? (string)$element['NAME'] : '');
}

function viewSectionName($value): string
{
    static $cache = [];
    $sectionId = (int)$value;
    if ($sectionId <= 0) return '';
    if (isset($cache[$sectionId])) return $cache[$sectionId];
    $section = CIBlockSection::GetByID($sectionId)->Fetch();
    return $cache[$sectionId] = ($section ? (string)$section['NAME'] : '');
}

function viewEnumName($value): string
{
    static $cache = [];
    $enumId = (int)$value;
    if ($enumId <= 0) return '';
    if (isset($cache[$enumId])) return $cache[$enumId];

    $enum = CIBlockPropertyEnum::GetByID($enumId);
    return $cache[$enumId] = ($enum ? (string)$enum['VALUE'] : '');
}

function viewPropertyIsUser(array $property): bool
{
    $userType = strtolower((string)($property['USER_TYPE'] ?? ''));
    return (int)($property['ID'] ?? 0) === VIEW_EMPLOYEE_PROPERTY_ID
        || $userType === 'employee'
        || strpos($userType, 'user') !== false;
}

function viewPropertyHasValue(array $property): bool
{
    $values = (array)($property['VALUE'] ?? []);
    foreach ($values as $value) {
        if (is_array($value)) $value = $value['TEXT'] ?? '';
        if ($value !== null && trim((string)$value) !== '') return true;
    }
    return false;
}

function viewPropertyHtml(array $property): string
{
    $values = (array)($property['VALUE'] ?? []);
    $enumValues = (array)($property['VALUE_ENUM'] ?? []);
    $result = [];

    foreach ($values as $index => $value) {
        if (is_array($value)) $value = $value['TEXT'] ?? '';
        if ($value === null || trim((string)$value) === '') continue;

        if (viewPropertyIsUser($property)) {
            $displayValue = viewUserName($value) ?: $value;
        } elseif (($property['PROPERTY_TYPE'] ?? '') === 'L') {
            $displayValue = ($enumValues[$index] ?? $enumValues[0] ?? viewEnumName($value)) ?: $value;
        } elseif (($property['PROPERTY_TYPE'] ?? '') === 'E') {
            $displayValue = viewElementName($value) ?: $value;
        } elseif (($property['PROPERTY_TYPE'] ?? '') === 'G') {
            $displayValue = viewSectionName($value) ?: $value;
        } elseif (($property['PROPERTY_TYPE'] ?? '') === 'F') {
            $path = CFile::GetPath((int)$value);
            if ($path) {
                $safePath = viewH($path);
                $result[] = '<a href="'.$safePath.'" target="_blank" rel="noopener">Скачать файл</a>';
                continue;
            }
            $displayValue = $value;
        } else {
            $displayValue = $value;
        }

        if ((string)$displayValue !== '') $result[] = nl2br(viewH($displayValue));
    }

    return implode('<br>', $result);
}

$requestId = (int)($_GET['id'] ?? 0);
$requestElement = null;
if ($requestId > 0) {
    $res = CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => VIEW_TRAINING_IBLOCK_ID,
            'ID' => $requestId,
            'ACTIVE' => 'Y',
            'CHECK_PERMISSIONS' => 'Y',
        ],
        false,
        false,
        // IBLOCK_ID необходим GetProperties(), чтобы загрузить все свойства
        // элемента, включая пользовательские поля списка PROPERTY_*.
        ['ID', 'IBLOCK_ID', 'NAME', 'DATE_CREATE', 'CREATED_BY']
    );
    $requestElement = $res->GetNextElement();
}
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

<div class="container mt-4 mb-4">
  <a href="/forms/learning/list.php" class="btn btn-outline-secondary mb-3">&larr; К списку заявок</a>

  <?php if (!$requestElement): ?>
    <div class="alert alert-danger">Заявка не найдена или у вас нет прав на её просмотр.</div>
  <?php else: ?>
    <?php
      $fields = $requestElement->GetFields();
      $properties = $requestElement->GetProperties(['SORT'=>'ASC', 'ID'=>'ASC'], []);
      $APPLICATION->SetTitle('Заявка на обучение №'.(int)$fields['ID']);
    ?>
    <h2 class="mb-3">Заявка на обучение №<?= (int)$fields['ID'] ?></h2>

    <div class="table-responsive">
      <table class="table table-bordered table-striped">
        <tbody>
          <tr><th style="width:35%">ID</th><td><?= (int)$fields['ID'] ?></td></tr>
          <?php if (!empty($fields['NAME'])): ?>
            <tr><th>Название</th><td><?= viewH($fields['NAME']) ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($fields['DATE_CREATE'])): ?>
            <tr><th>Дата создания</th><td><?= viewH($fields['DATE_CREATE']) ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($fields['CREATED_BY'])): ?>
            <tr><th>Автор заявки</th><td><?= viewH(viewUserName($fields['CREATED_BY'])) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($properties as $property): ?>
            <?php if (!viewPropertyHasValue($property)) continue; ?>
            <?php $propertyHtml = viewPropertyHtml($property); ?>
            <?php if ($propertyHtml === '') continue; ?>
            <tr>
              <th><?= viewH($property['NAME']) ?></th>
              <td><?= $propertyHtml ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); ?>

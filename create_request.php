<?php
/**
 * Скрипт: /forms/learning/create_request.php
 * Версия: v1.15.1 (2026-02-19)
 * - Если выбран тип обучения "Внутреннее обучение" — период обучения скрывается и не заполняется
 * - Перемещено поле "Вид обучения" сразу после "Город сотрудника"
 * - Добавлено поле "Город обучения" с логикой: список + текст при "Другой"
 * - Значение "Город обучения" записывается в PROPERTY_3062
 * - При "Дистанционно" в PROPERTY_3062 записывается "Дистанционно"
 * - Селектор сотрудника переведён на BX.UI.EntitySelector.Dialog (вместо bitrix:intranet.user.selector)
 * - Убран выбор сотрудника: заявка создаётся для текущего пользователя, выводятся ФИО и должность
 * - Поле "Тип обучения" перемещено вверх (сразу под ФИО сотрудника)
 */
use Bitrix\Main\Loader;
use Bitrix\Main\Context;

const TRAINING_IBLOCK_ID = 382;
const COURSES_IBLOCK_ID  = 386;
const STATUS_ELEMENT_ID  = 3472035;

// Свойства заявки
const PID_FIO                  = 3000;
const PID_CITY                 = 3001;
const PID_TOPIC                = 3002;
const PID_LINK                 = 3003;
const PID_DATE_FROM            = 3004;
const PID_DATE_TO              = 3005;
const PID_BUDGET               = 3006;
const PID_JUSTIFICATION        = 3007;
const PID_STATUS               = 3008;
const PID_TIP_OBUCHENIYA       = 3010;
const PID_VID_OBUCHENIYA       = 3034;
const PID_GOROD_OBUCHENIYA_WANT = 3062; // ➕ ДОРАБОТКА

// Значения списка TIP_OBUCHENIYA
const TIP_VNUTRENNEE = 6407;
const TIP_VNESHNEE   = 6408;

const WORKFLOW_TEMPLATE_ID = 1355;

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');

if (!Loader::includeModule('iblock') || !Loader::includeModule('lists')) {
    ShowError('Не удалось подключить модули iblock/lists.');
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    exit;
}

global $APPLICATION, $USER;
$APPLICATION->SetTitle('Заявка на обучение');

$request = Context::getCurrent()->getRequest();
$isPost  = $request->isPost();
$errors = [];
$successMessage = '';
$createdId = null;

// === Загрузка курсов ===
$courses = [];
$courseDescriptions = [];
$res = CIBlockElement::GetList(
    ['NAME' => 'ASC'],
    ['IBLOCK_ID' => COURSES_IBLOCK_ID, 'ACTIVE' => 'Y'],
    false,
    false,
    ['ID', 'NAME', 'PROPERTY_OPISANIE']
);
while ($item = $res->GetNext()) {
    $courses[$item['ID']] = $item['NAME'];
    $desc = '';
    if (isset($item['PROPERTY_OPISANIE_VALUE']) && is_array($item['PROPERTY_OPISANIE_VALUE'])) {
        if (isset($item['PROPERTY_OPISANIE_VALUE']['TEXT'])) {
            $desc = $item['PROPERTY_OPISANIE_VALUE']['TEXT'];
        }
    } else {
        $desc = $item['PROPERTY_OPISANIE_VALUE'] ?? '';
    }
    $desc = htmlspecialchars_decode($desc, ENT_QUOTES | ENT_HTML5);
    $courseDescriptions[$item['ID']] = $desc;
}

// === Загрузка "Вид обучения" ===
$vidObucheniyaList = [];
$resVid = CIBlockPropertyEnum::GetList(
    ["SORT" => "ASC"],
    ["PROPERTY_ID" => PID_VID_OBUCHENIYA]
);
while ($enum = $resVid->Fetch()) {
    $vidObucheniyaList[$enum['ID']] = $enum['VALUE'];
}

// === Вспомогательные функции ===
function h($v) { return htmlspecialcharsbx((string)$v); }

function training_fmt_date(string $s): ?string {
    $s = trim($s);
    if (!$s) return null;
    $ts = strtotime($s);
    if (!$ts) return null;
    return date('Y-m-d', $ts);
}

function logLearning($msg) {
    $f = $_SERVER['DOCUMENT_ROOT'].'/upload/logs/learning.log';
    @file_put_contents($f, '['.date('Y-m-d H:i:s')."] ".$msg."\n", FILE_APPEND);
}

// === Инициализация значений формы ===
$employeeId = (int)$USER->GetID(); // текущий пользователь
$employeeTitle = '';
$employeePosition = '';
$citySelect = '';
$cityOther = '';
$trainingType = '';
$vidObucheniya = '';
$gorodObucheniyaSelect = '';
$gorodObucheniyaOther = '';
$topic = '';
$link = '';
$dateFrom = '';
$dateTo = '';
$budget = 'да';
$justification = '';
$cityFinal = '';
$gorodObucheniyaFinal = '';

// === Обработка формы ===
if ($isPost && check_bitrix_sessid()) {
    // Сотрудник больше не выбирается: всегда текущий пользователь
    $employeeId = (int)$USER->GetID();

    $citySelect             = trim((string)$request->getPost('city_select'));
    $cityOther              = trim((string)$request->getPost('city_other'));
    $trainingType           = trim((string)$request->getPost('training_type'));
    $vidObucheniya          = trim((string)$request->getPost('vid_obucheniya'));
    $gorodObucheniyaSelect  = trim((string)$request->getPost('gorod_obucheniya_select'));
    $gorodObucheniyaOther   = trim((string)$request->getPost('gorod_obucheniya_other'));
    $topic                  = trim((string)$request->getPost('topic'));
    $link                   = trim((string)$request->getPost('link'));
    $dateFrom               = (string)$request->getPost('date_from');
    $dateTo                 = (string)$request->getPost('date_to');
    $budget                 = trim((string)$request->getPost('budget'));
    $justification          = trim((string)$request->getPost('justification'));

    $cityFinal = $trainingType === 'vnutrennee' ? '' : (($citySelect === 'Другой') ? $cityOther : $citySelect);

    // Период обучения: для внутреннего обучения не требуется
    $dateFromSave = '';
    $dateToSave   = '';

    if ($trainingType !== 'vnutrennee') {
        $dateFromSave = training_fmt_date($dateFrom);
        $dateToSave   = training_fmt_date($dateTo);
    }

    // === Валидация ===
    if ($trainingType !== 'vnutrennee' && !in_array($citySelect, ['Санкт-Петербург','Москва','Другой'], true)) {
        $errors[] = 'Неверный город.';
    }
    if ($trainingType !== 'vnutrennee' && $citySelect === 'Другой' && !$cityOther) $errors[] = 'Укажите город.';
    if (!in_array($trainingType, ['vneshnee','vnutrennee'], true)) {
        $errors[] = 'Выберите тип обучения.';
    }

    if ($trainingType === 'vneshnee') {
        if (!$vidObucheniya) {
            $errors[] = 'Укажите вид обучения.';
        } else {
            // Определяем текстовое значение "вида обучения"
            $vidText = $vidObucheniyaList[$vidObucheniya] ?? '';

            if ($vidText === 'Дистанционно') {
                $gorodObucheniyaFinal = 'Дистанционно';
            } elseif ($vidText === 'Очно') {
                if ($gorodObucheniyaSelect === 'Другой') {
                    if (!$gorodObucheniyaOther) {
                        $errors[] = 'Укажите город обучения.';
                    } else {
                        $gorodObucheniyaFinal = $gorodObucheniyaOther;
                    }
                } elseif (in_array($gorodObucheniyaSelect, ['Санкт-Петербург', 'Москва'], true)) {
                    $gorodObucheniyaFinal = $gorodObucheniyaSelect;
                } else {
                    $errors[] = 'Некорректный город обучения.';
                }
            } else {
                // Другие значения — не требуют города
                $gorodObucheniyaFinal = '';
            }
        }
    }

    if ($trainingType === 'vnutrennee') {
        if (!isset($courses[$topic])) $errors[] = 'Выберите курс.';
    } else {
        if (!$topic) $errors[] = 'Укажите тему обучения.';
        if (!in_array($budget, ['да','нет'], true)) $errors[] = 'Выберите наличие бюджета.';
        if ($budget === 'нет' && !$justification) $errors[] = 'Обоснование обязательно.';
    }

    if ($trainingType !== 'vnutrennee') {
        if (!$dateFromSave) $errors[] = 'Дата начала некорректна.';
        if (!$dateToSave)   $errors[] = 'Дата окончания некорректна.';
        if ($dateFromSave && $dateToSave && strtotime($dateToSave) < strtotime($dateFromSave)) {
            $errors[] = 'Дата окончания раньше начала.';
        }
    }

    // Ограничение: внешнее обучение — дата не ранее +1 месяца
    if ($trainingType === 'vneshnee' && $dateFromSave) {
        try {
            $minStart = (new \DateTime('now', new \DateTimeZone(date_default_timezone_get())))->modify('+1 month')->format('Y-m-d');
        } catch (\Throwable $e) {
            $minStart = date('Y-m-d', strtotime('+1 month'));
        }
        if (strtotime($dateFromSave) < strtotime($minStart)) {
            $humanMin = date('d.m.Y', strtotime($minStart));
            $errors[] = 'Для внешнего обучения дата начала должна быть не ранее ' . $humanMin . '.';
        }
    }

    // === Сохранение ===
    if (empty($errors)) {
        $tipValue = ($trainingType === 'vnutrennee') ? TIP_VNUTRENNEE : TIP_VNESHNEE;
        $topicValue = $trainingType === 'vnutrennee' ? ($courses[$topic] ?? '') : $topic;

        $el = new CIBlockElement();
        $arFields = [
            'IBLOCK_ID' => TRAINING_IBLOCK_ID,
            'ACTIVE' => 'Y',
            'NAME' => 'Заявка на обучение (черновик)',
            'CREATED_BY' => (int)$USER->GetID(),
            'PROPERTY_VALUES' => [
                PID_FIO                  => $employeeId,
                PID_CITY                 => $cityFinal,
                PID_TOPIC                => $topicValue,
                PID_LINK                 => $trainingType === 'vneshnee' ? $link : '',
                PID_DATE_FROM            => $dateFromSave,
                PID_DATE_TO              => $dateToSave,
                PID_BUDGET               => $trainingType === 'vneshnee' ? $budget : '',
                PID_JUSTIFICATION        => $trainingType === 'vneshnee' ? $justification : '',
                PID_STATUS               => STATUS_ELEMENT_ID,
                PID_TIP_OBUCHENIYA       => $tipValue,
                PID_VID_OBUCHENIYA       => $trainingType === 'vneshnee' ? $vidObucheniya : '',
                PID_GOROD_OBUCHENIYA_WANT => $gorodObucheniyaFinal, // ➕ ДОРАБОТКА
            ],
        ];

        if ($ID = $el->Add($arFields)) {
            $createdId = (int)$ID;
            $successMessage = 'Заявка сохранена (#'.$createdId.').';

            if (Loader::includeModule("bizproc")) {
                $documentId = ["lists", "Bitrix\\Lists\\BizprocDocumentLists", $ID];
                $wfErrors = [];
                $wfId = CBPDocument::StartWorkflow(
                    WORKFLOW_TEMPLATE_ID,
                    $documentId,
                    [],
                    $wfErrors
                );
                if (!$wfId) {
                    $errors[] = "Бизнес-процесс не запустился: " . implode("; ", $wfErrors);
                }
            }

            // Сброс формы
            $citySelect = $cityOther = $trainingType = $vidObucheniya = '';
            $gorodObucheniyaSelect = $gorodObucheniyaOther = '';
            $topic = $link = $dateFrom = $dateTo = $justification = '';
            $budget = 'да';
        } else {
            $errors[] = 'Ошибка: ' . htmlspecialcharsbx($el->LAST_ERROR);
        }
    }
}

// === Данные текущего сотрудника для вывода и сохранения ===
if ($employeeId > 0) {
    $u = CUser::GetByID($employeeId)->Fetch();
    if ($u) {
        $employeeTitle = trim(
            ($u['LAST_NAME'] ?? '') . ' ' . ($u['NAME'] ?? '') . ' ' . ($u['SECOND_NAME'] ?? '')
        );
        if ($employeeTitle === '') {
            $employeeTitle = (string)($u['LOGIN'] ?? '');
        }
        $employeePosition = trim((string)($u['WORK_POSITION'] ?? ''));
    }
}

CJSCore::Init(['ajax','jquery','date','ui']);
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<div class="container mt-4 mb-4">
  <h2>Заявка на обучение</h2>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post">
    <?= bitrix_sessid_post() ?>
    <!-- Сотрудник (текущий пользователь) -->
    <div class="form-group">
      <label>Сотрудник *</label>

      <!-- Сохраняем совместимость: значение как и раньше U<ID>, но выбора нет -->
      <input type="hidden" name="employee_id" id="employee_id" value="<?= $employeeId ? 'U'.(int)$employeeId : '' ?>">

      <div class="form-control" style="height:auto;">
        <div><strong><?= h($employeeTitle) ?></strong></div>
        <?php if ($employeePosition !== ''): ?>
          <div class="text-muted" style="font-size: 0.9em;"><?= h($employeePosition) ?></div>
        <?php endif; ?>
      </div>
      <small class="form-text text-muted">Заявка будет создана на текущего пользователя.</small>
    </div>

    <!-- Тип обучения (перемещено вверх: сразу после ФИО сотрудника) -->
    <div class="form-group">
      <label>Тип обучения *</label>
      <select class="form-control" name="training_type" id="training_type" required>
        <option value="">— выберите —</option>
        <option value="vneshnee" <?= $trainingType==='vneshnee'?'selected':'' ?>>Внешнее обучение</option>
        <option value="vnutrennee" <?= $trainingType==='vnutrennee'?'selected':'' ?>>Внутреннее обучение</option>
      </select>
    </div>

    <!-- Город -->
    <div class="form-group" id="employee_city_wrap" style="<?= $trainingType==='vnutrennee'?'display:none':'' ?>">
      <label>Город сотрудника *</label>
      <select class="form-control" name="city_select" id="city_select" required>
        <option value="" <?= $citySelect===''?'selected':'' ?>>— выберите —</option>
        <option value="Санкт-Петербург" <?= $citySelect==='Санкт-Петербург'?'selected':'' ?>>Санкт-Петербург</option>
        <option value="Москва" <?= $citySelect==='Москва'?'selected':'' ?>>Москва</option>
        <option value="Другой" <?= $citySelect==='Другой'?'selected':'' ?>>Другой</option>
      </select>
      <div id="city_other_wrap" class="mt-2" style="<?= $citySelect==='Другой'?'display:block':'display:none' ?>">
        <input type="text" class="form-control" name="city_other" value="<?= h($cityOther) ?>" placeholder="Город">
      </div>
    </div>


    <!-- ➕ ДОРАБОТКА: Вид обучения (только внешнее) -->
    <div class="form-group" id="vid_obucheniya_wrap" style="<?= $trainingType==='vnutrennee'?'display:none':'' ?>">
        <label>Вид обучения *</label>
        <select class="form-control" name="vid_obucheniya" id="vid_obucheniya_select">
            <option value="">— выберите —</option>
            <?php foreach ($vidObucheniyaList as $id => $name): ?>
                <option value="<?= (int)$id ?>" <?= $vidObucheniya == $id ? 'selected' : '' ?>><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- ➕ ДОРАБОТКА: Город обучения -->
    <div class="form-group" id="gorod_obucheniya_wrap" style="display:none;">
        <label>Город обучения *</label>
        <select class="form-control" name="gorod_obucheniya_select" id="gorod_obucheniya_select">
            <option value="">— выберите —</option>
            <option value="Санкт-Петербург" <?= $gorodObucheniyaSelect === 'Санкт-Петербург' ? 'selected' : '' ?>>Санкт-Петербург</option>
            <option value="Москва" <?= $gorodObucheniyaSelect === 'Москва' ? 'selected' : '' ?>>Москва</option>
            <option value="Другой" <?= $gorodObucheniyaSelect === 'Другой' ? 'selected' : '' ?>>Другой</option>
        </select>
        <div id="gorod_obucheniya_other_wrap" class="mt-2" style="<?= $gorodObucheniyaSelect === 'Другой' ? 'display:block' : 'display:none' ?>">
            <input type="text" class="form-control" name="gorod_obucheniya_other" value="<?= h($gorodObucheniyaOther) ?>" placeholder="Город">
        </div>
    </div>

    <!-- Тема/курс -->
    <div class="form-group" id="topic_wrap"></div>

    <!-- Ссылка (только внешнее) -->
    <div class="form-group" id="link_wrap" style="<?= $trainingType==='vnutrennee'?'display:none':'' ?>">
      <label>Ссылка на обучение</label>
      <input type="url" class="form-control" name="link" value="<?= h($link) ?>" placeholder="https://...">
    </div>

    <!-- Даты (только внешнее) -->
    <div class="form-group" id="dates_wrap" style="<?= $trainingType==='vnutrennee'?'display:none':'' ?>">
      <label>Период обучения *</label>
      <div class="form-inline">
        <input type="date" class="form-control mr-2" name="date_from" value="<?= h($dateFrom) ?>">
        <span class="mr-2">—</span>
        <input type="date" class="form-control" name="date_to" value="<?= h($dateTo) ?>">
      </div>
    </div>

    <!-- Бюджет -->
    <div class="form-group" id="budget_wrap" style="<?= $trainingType==='vnutrennee'?'display:none':'' ?>">
      <label>Наличие бюджета *</label><br>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="budget" value="да" id="budget_yes" <?= $budget==='да'?'checked':'' ?>>
        <label class="form-check-label" for="budget_yes">Да</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="budget" value="нет" id="budget_no" <?= $budget==='нет'?'checked':'' ?>>
        <label class="form-check-label" for="budget_no">Нет</label>
      </div>
    </div>

    <!-- Обоснование -->
    <div class="form-group" id="justify_wrap" style="<?= ($trainingType==='vnutrennee' || $budget!=='нет')?'display:none':'' ?>">
      <label>Обоснуйте, почему вам необходимо пройти данное обучение *</label>
      <textarea class="form-control" rows="3" name="justification"><?= h($justification) ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">Сохранить</button>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Город сотрудника
  var citySelect = document.getElementById('city_select');
  var cityOtherWrap = document.getElementById('city_other_wrap');
  if (citySelect) {
    citySelect.addEventListener('change', function(){
      cityOtherWrap.style.display = (this.value === 'Другой') ? 'block' : 'none';
    });
  }

  // Тип обучения
  var trainingTypeSelect = document.getElementById('training_type');
  var employeeCityWrap = document.getElementById('employee_city_wrap');
  var topicWrap = document.getElementById('topic_wrap');
  var vidObucheniyaWrap = document.getElementById('vid_obucheniya_wrap');
  var gorodObucheniyaWrap = document.getElementById('gorod_obucheniya_wrap');
  var gorodObucheniyaSelect = document.getElementById('gorod_obucheniya_select');
  var gorodObucheniyaOtherWrap = document.getElementById('gorod_obucheniya_other_wrap');
  var linkWrap = document.getElementById('link_wrap');
  var budgetWrap = document.getElementById('budget_wrap');
  var justifyWrap = document.getElementById('justify_wrap');
  var datesWrap = document.getElementById('dates_wrap');
  var dateFromInput = document.querySelector('input[name="date_from"]');
  var dateToInput   = document.querySelector('input[name="date_to"]');

  // ➕ ДОРАБОТКА: обновление "города обучения"
  function updateGorodObucheniyaVisibility() {
    var vidSelect = document.getElementById('vid_obucheniya_select');
    if (!vidSelect || !gorodObucheniyaWrap) return;

    var vidId = vidSelect.value;
    var vidText = vidSelect.options[vidSelect.selectedIndex]?.text || '';

    if (vidText === 'Очно') {
      gorodObucheniyaWrap.style.display = 'block';
    } else {
      gorodObucheniyaWrap.style.display = 'none';
    }

    // Скрываем поле "Другой", если не выбран
    if (gorodObucheniyaSelect && gorodObucheniyaOtherWrap) {
      gorodObucheniyaOtherWrap.style.display = (gorodObucheniyaSelect.value === 'Другой') ? 'block' : 'none';
    }
  }

  // Обновление формы при смене типа обучения
  function updateForm() {
    var type = trainingTypeSelect.value;
    if (type === 'vnutrennee') {
      if (employeeCityWrap) employeeCityWrap.style.display = 'none';
      if (citySelect) citySelect.required = false;
      var options = '<option value="">— выберите —</option>';
      <?php foreach ($courses as $id => $name): ?>
        options += '<option value="<?= (int)$id ?>"><?= CUtil::JSEscape($name) ?></option>';
      <?php endforeach; ?>
      topicWrap.innerHTML = `
        <label>Курс *</label>
        <select class="form-control" name="topic" id="topic_select">
          ${options}
        </select>
        <div id="course_description" class="mt-2 p-3 bg-light rounded" style="display:none;"></div>
      `;
      vidObucheniyaWrap.style.display = 'none';
      gorodObucheniyaWrap.style.display = 'none';
      linkWrap.style.display = 'none';
      budgetWrap.style.display = 'none';
      justifyWrap.style.display = 'none';

      if (datesWrap) datesWrap.style.display = 'none';
      if (dateFromInput) { dateFromInput.required = false; dateFromInput.value = ''; }
      if (dateToInput)   { dateToInput.required = false; dateToInput.value = ''; }

      var sel = document.getElementById('topic_select');
      if (sel) {
        sel.value = '<?= h($topic) ?>';
        sel.addEventListener('change', function(){
          var descDiv = document.getElementById('course_description');
          if (descDiv) {
            var descs = <?= json_encode($courseDescriptions, JSON_UNESCAPED_UNICODE) ?>;
            descDiv.innerHTML = descs[this.value] || '';
            descDiv.style.display = this.value ? 'block' : 'none';
          }
        });
        if (sel.value) {
          var descDiv = document.getElementById('course_description');
          if (descDiv) {
            var descs = <?= json_encode($courseDescriptions, JSON_UNESCAPED_UNICODE) ?>;
            descDiv.innerHTML = descs[sel.value] || '';
            descDiv.style.display = 'block';
          }
        }
      }
    } else {
      if (employeeCityWrap) employeeCityWrap.style.display = 'block';
      if (citySelect) citySelect.required = true;
      topicWrap.innerHTML = `
        <label>Тема обучения *</label>
        <textarea class="form-control" rows="3" name="topic"><?= h($topic) ?></textarea>
      `;
      vidObucheniyaWrap.style.display = 'block';
      linkWrap.style.display = 'block';
      budgetWrap.style.display = 'block';
      if (datesWrap) datesWrap.style.display = 'block';
      if (dateFromInput) dateFromInput.required = true;
      if (dateToInput)   dateToInput.required = true;

      var no = document.getElementById('budget_no');
      justifyWrap.style.display = (no && no.checked) ? 'block' : 'none';
    }
    updateGorodObucheniyaVisibility();
  }

  // Подписки
  if (trainingTypeSelect) {
    trainingTypeSelect.addEventListener('change', updateForm);
  }

  // Бюджет → обоснование
  function toggleJustify() {
    var no = document.getElementById('budget_no');
    if (justifyWrap && no) {
      justifyWrap.style.display = (no.checked && trainingTypeSelect.value !== 'vnutrennee') ? 'block' : 'none';
    }
  }
  document.addEventListener('change', function(e){
    if (e.target && e.target.id === 'budget_no') toggleJustify();
  });

  // Обработка смены "вида обучения"
  var vidSelect = document.getElementById('vid_obucheniya_select');
  if (vidSelect) {
    vidSelect.addEventListener('change', updateGorodObucheniyaVisibility);
  }

  // Обработка смены города обучения
  if (gorodObucheniyaSelect) {
    gorodObucheniyaSelect.addEventListener('change', function(){
      if (gorodObucheniyaOtherWrap) {
        gorodObucheniyaOtherWrap.style.display = (this.value === 'Другой') ? 'block' : 'none';
      }
    });
  }

  // Уведомление при успехе
  <?php if ($successMessage): ?>
    BX.UI.Notification.Center.notify({
      content: "<?= CUtil::JSEscape($successMessage) ?>",
      color: "success", autoHideDelay: 2000
    });
    setTimeout(() => window.location.href = "/forms/learning/list.php", 2200);
  <?php endif; ?>

  // Инициализация
  updateForm();
  toggleJustify();

  // Дата: ограничение на начало для внешнего
  function applyExternalDateMin() {
    var typeSel = document.getElementById('training_type');
    var df = document.querySelector('input[name="date_from"]');
    var dt = document.querySelector('input[name="date_to"]');
    if (!typeSel || !df || !dt) return;

    if (typeSel.value === 'vneshnee') {
      var now = new Date();
      now.setMonth(now.getMonth() + 1);
      var y = now.getFullYear();
      var m = String(now.getMonth() + 1).padStart(2,'0');
      var d = String(now.getDate()).padStart(2,'0');
      var minVal = y + '-' + m + '-' + d;

      df.min = minVal;
      dt.min = minVal;
    } else {
      df.removeAttribute('min');
      dt.removeAttribute('min');
    }
  }
  applyExternalDateMin();
  if (trainingTypeSelect) trainingTypeSelect.addEventListener('change', applyExternalDateMin);
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); ?>

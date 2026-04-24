<?php
session_start();
require_once 'session_timeout.php';
require_once 'auth_check.php'; // Проверка авторизации

$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Определение ссылки "Главная" по роли
$home_link = ($_SESSION['role'] === 'admin') ? 'admin.php' : 'auth.php';

// Обработка выхода
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: index.php");
    exit();
}

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    echo "<p>Вы должны быть авторизованы для просмотра страницы.</p>";
    exit();
}

$message = "";

// Вкладки (только для админа): individual / common
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'individual';
if ($tab !== 'common') {
    $tab = 'individual';
}

/*
   ОБРАБОТКА ФОРМЫ «Ввести показания» (submit_reading)
   --------------------------------------------------
   - user (жилец):
       1) только 1 раз в месяц,
       2) только в интервале 18..25 число,
       3) не может ставить показания меньше предыдущих (глобально),
       4) если в этом месяце уже есть показание (от кого угодно), запрещено повторить,
       5) если счёт (bill) за месяц сформирован, менять нельзя.
   - admin (УК):
       - может несколько раз в месяц менять показания (старое затирается),
       - может указывать меньше предыдущих,
       - если счёт сформирован, менять нельзя.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reading'])) {
    $meter_id = $_POST['meter_id'] ?? null;
    $value    = $_POST['value'] ?? null;
    $role     = $_SESSION['role'];

    // user_id=NULL, если admin; иначе user_id = session
    $user_id = ($role === 'admin') ? null : $_SESSION['user_id'];

    if (!$meter_id || !is_numeric($value)) {
        $message = "Ошибка: некорректные данные для показаний!";
    } else {
        // Доп.проверка даты для user (только 18..25)
        if ($role === 'user') {
            $day = date('j'); // число месяца
            if ($day < 18 || $day > 25) {
                $message = "Ошибка: Показания можно вносить только с 18 по 25 число!";
            }
        }

        if (empty($message)) {
            $currentYM = date('Y-m'); // формат '2023-09'

            // 1) Проверяем, нет ли сформированного счёта (bills)
            $sql_bill = "
                SELECT bill_id 
                FROM bills
                WHERE meter_id=?
                  AND billing_period=?
            ";
            if ($role === 'user') {
                $sql_bill .= " AND user_id=? ";
            } else {
                $sql_bill .= " AND user_id IS NULL ";
            }
            $sql_bill .= " LIMIT 1 ";

            $stmtBill = $conn->prepare($sql_bill);
            if ($role === 'user') {
                $stmtBill->bind_param("isi", $meter_id, $currentYM, $user_id);
            } else {
                $stmtBill->bind_param("is", $meter_id, $currentYM);
            }
            $stmtBill->execute();
            $stmtBill->store_result();
            if ($stmtBill->num_rows > 0) {
                $message = "Ошибка: Нельзя менять показания, счёт за этот месяц уже сформирован!";
            }
            $stmtBill->close();

            if (empty($message)) {
                // 2) Проверяем, есть ли уже показания в этом месяце (неважно, кто внёс)
                $sql_check = "
                    SELECT reading_id, value
                    FROM meter_readings
                    WHERE meter_id=?
                      AND DATE_FORMAT(data, '%Y-%m')=?
                    LIMIT 1
                ";
                $stmt_c = $conn->prepare($sql_check);
                $stmt_c->bind_param("is", $meter_id, $currentYM);
                $stmt_c->execute();
                $stmt_c->store_result();

                $old_rid = null;
                $old_val = null;
                if ($stmt_c->num_rows > 0) {
                    $stmt_c->bind_result($old_rid, $old_val);
                    $stmt_c->fetch();
                }
                $stmt_c->close();

                // Если user и уже есть запись => запрещаем повтор
                if ($role === 'user' && $old_rid !== null) {
                    $message = "Ошибка: Показания за этот месяц уже есть, менять нельзя!";
                }

                if (empty($message)) {
                    // Если admin, затираем старое показание
                    if ($role === 'admin' && $old_rid !== null) {
                        $delSql = "DELETE FROM meter_readings WHERE reading_id=?";
                        $stmt_del = $conn->prepare($delSql);
                        $stmt_del->bind_param("i", $old_rid);
                        $stmt_del->execute();
                        $stmt_del->close();
                    }

                    // Если user, проверяем «не меньше предыдущих»
                    if ($role === 'user') {
                        $sql_prev = "
                            SELECT value 
                            FROM meter_readings
                            WHERE meter_id=?
                            ORDER BY data DESC
                            LIMIT 1
                        ";
                        $stmt_p = $conn->prepare($sql_prev);
                        $stmt_p->bind_param("i", $meter_id);
                        $stmt_p->execute();
                        $stmt_p->bind_result($prev_val);
                        $stmt_p->fetch();
                        $stmt_p->close();

                        if ($prev_val !== null && $value < $prev_val) {
                            $message = "Ошибка: Новые показания ($value) меньше предыдущих ($prev_val)!";
                        }
                    }

                    // Если всё ещё ок
                    if (empty($message)) {
                        // Вставляем запись
                        $stmt_ins = $conn->prepare("
                            INSERT INTO meter_readings (meter_id, user_id, value)
                            VALUES (?,?,?)
                        ");
                        if ($role === 'admin') {
                            // admin => user_id=NULL
                            $nullVal = null;
                            $stmt_ins->bind_param("iid", $meter_id, $nullVal, $value);
                        } else {
                            $stmt_ins->bind_param("iid", $meter_id, $user_id, $value);
                        }
                        if ($stmt_ins->execute()) {
                            $message = ($role==='admin')
                                ? "Показания (admin) успешно добавлены/изменены за этот месяц."
                                : "Показания успешно внесены (user).";
                        } else {
                            $message = "Ошибка добавления показаний: " . $stmt_ins->error;
                        }
                        $stmt_ins->close();
                    }
                }
            }
        }
    }

    /*
       После обработки формы — выполняем PRG, 
       чтобы при обновлении страницы форма не отправлялась повторно.
    */
    header("Location: pokazaniya.php?tab=$tab&message=" . urlencode($message));
    exit();
}

// Считываем $message из GET, если есть
if (isset($_GET['message']) && $_GET['message'] !== '') {
    $message = $_GET['message'];
}

/*
  МЯГКОЕ УДАЛЕНИЕ И РЕДАКТИРОВАНИЕ ОБЩЕДОМОВЫХ (ADMIN)
  --------------------------------------------------
*/
if ($_SESSION['role'] === 'admin') {
    // 1. Мягкое удаление счётчика (общедомового)
    if (isset($_GET['soft_delete_meter_id']) && $tab==='common') {
        $del_id = (int)$_GET['soft_delete_meter_id'];

        // Проверка, что счётчик общедомовой, принадлежит УК, не удалён
        $check_sql = "
            SELECT m.meter_id
            FROM meter m
            JOIN address a ON m.address_id=a.address_id
            WHERE m.meter_id=?
              AND m.meter_type='общедомовой'
              AND m.is_deleted=0
              AND a.management_company_id=?
        ";
        $stmt_d = $conn->prepare($check_sql);
        $stmt_d->bind_param("ii", $del_id, $_SESSION['user_id']);
        $stmt_d->execute();
        $stmt_d->store_result();
        if ($stmt_d->num_rows>0) {
            $upd_sql = "UPDATE meter SET is_deleted=1 WHERE meter_id=?";
            $stmt_up = $conn->prepare($upd_sql);
            $stmt_up->bind_param("i", $del_id);
            if ($stmt_up->execute()) {
                $message = "Общедомовой счётчик помечен как удалён.";
            } else {
                $message = "Ошибка удаления: " . $stmt_up->error;
            }
            $stmt_up->close();
        }
        $stmt_d->close();

        // PRG после мягкого удаления
        header("Location: pokazaniya.php?tab=common&message=" . urlencode($message));
        exit();
    }

    // 2. Редактирование счётчика (общедомового)
    if (isset($_POST['edit_common_meter']) && $tab==='common') {
        $meter_id   = (int)($_POST['meter_id'] ?? 0);
        $serial     = trim($_POST['edit_serial'] ?? '');
        $r_type     = trim($_POST['edit_resource_type'] ?? 'ХВС');
        $inst_date  = $_POST['edit_installation_date'] ?? date('Y-m-d');
        $check_date = $_POST['edit_last_check'] ?? date('Y-m-d');

        // Проверка дат
        if ($check_date < $inst_date) {
            $message = "Ошибка: дата поверки не может быть раньше даты установки!";
        } else {
            // Уникальность серийника
            $chk_sql = "
                SELECT meter_id FROM meter
                WHERE meter_serial_number=? 
                  AND meter_id<>?
                LIMIT 1
            ";
            $stmt_chk = $conn->prepare($chk_sql);
            $stmt_chk->bind_param("si", $serial, $meter_id);
            $stmt_chk->execute();
            $stmt_chk->store_result();
            if ($stmt_chk->num_rows>0) {
                $message = "Ошибка: Счётчик с сер. номером '$serial' уже существует!";
            }
            $stmt_chk->close();

            if (empty($message)) {
                $upd_sql = "
                    UPDATE meter
                    SET meter_serial_number=?,
                        resource_type=?,
                        installation_date=?,
                        last_check=?
                    WHERE meter_id=? 
                      AND is_deleted=0
                ";
                $stmt_upd = $conn->prepare($upd_sql);
                $stmt_upd->bind_param("ssssi",
                    $serial, $r_type, $inst_date, $check_date, $meter_id
                );
                if ($stmt_upd->execute()) {
                    $message = "Данные счётчика успешно изменены.";
                } else {
                    $message = "Ошибка обновления: " . $stmt_upd->error;
                }
                $stmt_upd->close();
            }
        }

        // PRG
        header("Location: pokazaniya.php?tab=common&message=" . urlencode($message));
        exit();
    }
}

/*
   ВЫБОРКА СЧЁТЧИКОВ ДЛЯ ОТОБРАЖЕНИЯ
   (admin => individual/common, user => только свои)
   ------------------------------------------------
*/
// Если админ
if ($_SESSION['role']==='admin') {
    if ($tab==='common') {
        // Общедомовые счётчики
        $searchC = isset($_GET['searchCommon']) ? trim($_GET['searchCommon']) : '';
        $sql_common = "
            SELECT m.meter_id,
                   m.meter_serial_number,
                   m.resource_type,
                   m.installation_date,
                   m.last_check,
                   a.region, a.city, a.street, a.home, a.corpus, a.post_index, a.flat_number,
                   (SELECT value FROM meter_readings
                    WHERE meter_id=m.meter_id
                    ORDER BY data DESC LIMIT 1) AS last_reading,
                   (SELECT data FROM meter_readings
                    WHERE meter_id=m.meter_id
                    ORDER BY data DESC LIMIT 1) AS last_reading_date
            FROM meter m
            JOIN address a ON m.address_id=a.address_id
            WHERE m.meter_type='общедомовой'
              AND m.is_deleted=0
              AND a.management_company_id=?
        ";
        if ($searchC!=='') {
            $sql_common .= "
              AND (
                m.meter_serial_number LIKE ?
                OR CONCAT(a.region,' ',a.city,' ',a.street,' ',a.home,' ',
                          IFNULL(a.corpus,''),' ',IFNULL(a.flat_number,'')) LIKE ?
              )
            ";
        }
        $stmt_c = $conn->prepare($sql_common);
        if ($searchC!=='') {
            $likeC = "%$searchC%";
            $stmt_c->bind_param("iss", $_SESSION['user_id'], $likeC, $likeC);
        } else {
            $stmt_c->bind_param("i", $_SESSION['user_id']);
        }
        $stmt_c->execute();
        $result_common = $stmt_c->get_result();
        $stmt_c->close();

    } else {
        // Индивидуальные => список жильцов
        $searchI = isset($_GET['searchInd']) ? trim($_GET['searchInd']) : '';
        $sql_users = "
            SELECT
                u.user_id,
                u.fullname,
                u.login,
                u.phone,
                u.email,
                u.account_number,
                CONCAT(a.region, ', ', a.city, ', ', a.street, ' ', a.home,
                       IF(a.corpus IS NOT NULL AND a.corpus<>'', CONCAT(' корп.', a.corpus), ''),
                       IF(a.flat_number IS NOT NULL AND a.flat_number<>'', CONCAT(' кв.', a.flat_number), '')
                ) AS full_address
            FROM users u
            JOIN address a ON u.address_id=a.address_id
            WHERE a.management_company_id=?
        ";
        if ($searchI!=='') {
            $sql_users .= "
              AND (
                u.fullname LIKE ?
                OR u.account_number LIKE ?
                OR CONCAT(a.region,' ',a.city,' ',a.street,' ',a.home,' ',
                  IFNULL(a.corpus,''),' ',IFNULL(a.flat_number,'')) LIKE ?
              )
            ";
        }
        $sql_users .= " ORDER BY u.fullname ASC";

        $stmt_u = $conn->prepare($sql_users);
        if ($searchI!=='') {
            $likeS = "%$searchI%";
            $stmt_u->bind_param("isss", $_SESSION['user_id'], $likeS, $likeS, $likeS);
        } else {
            $stmt_u->bind_param("i", $_SESSION['user_id']);
        }
        $stmt_u->execute();
        $res_users = $stmt_u->get_result();
        $stmt_u->close();
    }
}
// Если user (жилец)
else {
    $sql_um = "
        SELECT 
            m.meter_id,
            m.meter_serial_number,
            m.resource_type,
            m.meter_type,
            m.installation_date,
            m.last_check,
            a.region, a.city, a.street, a.home, a.corpus, a.post_index, a.flat_number,
            (SELECT value FROM meter_readings
             WHERE meter_id=m.meter_id
             ORDER BY data DESC
             LIMIT 1) AS last_reading,
            (SELECT data FROM meter_readings
             WHERE meter_id=m.meter_id
             ORDER BY data DESC
             LIMIT 1) AS last_reading_date
        FROM meter m
        JOIN address a ON m.address_id=a.address_id
        WHERE m.meter_type='индивидуальный'
          AND m.is_deleted=0
          AND m.address_id=(
             SELECT address_id
             FROM users
             WHERE user_id=?
          )
    ";
    $stmt_uu = $conn->prepare($sql_um);
    $stmt_uu->bind_param("i", $_SESSION['user_id']);
    $stmt_uu->execute();
    $res_user_meters = $stmt_uu->get_result();
    $stmt_uu->close();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Показания счетчиков - ЖКХ Город</title>
  <style>
    /* ===== Ваши стили, неизменённые ===== */
    body {
      font-family: Arial, sans-serif;
      margin:0; padding:0;
      background: linear-gradient(135deg, #6dd5ed, #2193b0);
      color: #fff;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }
    header {
      background: rgba(0,0,0,0.6);
      padding: 25px;
      text-align: center;
      position: fixed;
      top:0; left:0; width:100%;
      z-index:1000;
    }
    header h1 { margin:0; font-size:2.5em; color:#fff; }
    nav {
      display:flex; justify-content:center; gap:30px;
      margin-top:25px;
    }
    nav a {
      color:#fff; text-decoration:none; font-size:1.3em;
      padding:12px 30px; border:2px solid #fff; border-radius:25px;
      transition:0.3s;
    }
    nav a:hover, nav a.active {
      background:#fff; color:#2193b0;
    }
    .logout-btn {
      position:absolute; top:20px; right:20px;
      background:red; color:#fff; border:none; 
      padding:12px 20px; border-radius:5px; cursor:pointer; font-size:1em;
    }
    .logout-btn:hover {
      background:darkred;
    }
    .content {
      padding:160px 20px 50px;
      flex:1; text-align:center;
    }
    .tabs {
      margin-top:20px;
    }
    .tabs a {
      color:#fff; text-decoration:none; font-size:1.3em;
      padding:12px 30px; border:2px solid #fff; border-radius:25px;
      transition:0.3s; margin:0 10px;
    }
    .tabs a:hover, .tabs a.active-tab {
      background:#fff; color:#2193b0;
    }
    .message {
      color:yellow; margin-bottom:15px; font-weight:bold;
    }
    .search-form {
      margin:20px auto;
    }
    .search-form input[type="text"] {
      padding:8px; width:300px; border:1px solid #ccc; border-radius:5px;
    }
    .search-form button {
      padding:8px16px; background:#2193b0; color:#fff; border:none;
      border-radius:5px; cursor:pointer; font-size:1em; margin-left:10px;
    }
    .search-form button:hover { background:#17677d; }
    table {
      width:80%; margin:0 auto; border-collapse:collapse;
      background:#fff; color:#000;
    }
    table th, table td {
      padding:15px; border:1px solid #ddd; text-align:center; vertical-align:middle;
    }
    table th {
      background:#2193b0; color:#fff;
    }
    table th:last-child, table td:last-child {
      width:220px;
    }
    .info-btn {
      display:inline-block; padding:12px 30px; background:transparent;
      border:2px solid #fff; border-radius:25px; color:#fff;
      text-decoration:none; font-size:1em; cursor:pointer;
      transition:all 0.3s ease; margin:5px 0;
    }
    .info-btn:hover {
      background:#fff; color:#2193b0; transform:translateY(-2px);
      box-shadow:0 4px 15px rgba(0,0,0,0.2);
    }
    .edit-meter-form {
      background:rgba(0,0,0,0.2); padding:10px; border-radius:10px;
      margin-top:10px; display:inline-block;
    }
    .edit-meter-form label {
      display:block; margin:5px 0; color:#fff; font-weight:bold;
    }
    .edit-meter-form input[type="date"],
    .edit-meter-form input[type="text"],
    .edit-meter-form select {
      margin-bottom:10px; width:80%; padding:6px; border-radius:5px; border:1px solid #ccc;
    }
    .delete-link {
      display:inline-block; margin-top:5px; color:red; text-decoration:none; font-weight:bold;
    }
    .delete-link:hover { color:darkred; }
    footer {
      text-align:center; padding:20px; background:rgba(0,0,0,0.8);
      color:#fff; margin-top:auto; font-size:1em;
    }
  </style>
</head>
<body>
<header>
  <h1>Показания счетчиков</h1>
  <form method="POST">
    <button type="submit" name="logout" class="logout-btn">Выйти</button>
  </form>
  <nav>
    <a href="<?php echo $home_link; ?>" 
       class="<?php echo (basename($_SERVER['PHP_SELF'])=='auth.php' || basename($_SERVER['PHP_SELF'])=='admin.php') ? 'active' : ''; ?>">
       Главная
    </a>
    <a href="tariffs.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='tariffs.php'?'active':'';?>">
      Тарифы
    </a>
    <a href="pokazaniya.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='pokazaniya.php'?'active':'';?>">
      Показания
    </a>
    <a href="bills.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='bills.php'?'active':'';?>">
      Начисления
    </a>
    <a href="payments.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='payments.php'?'active':'';?>">
      Платежи
    </a>
    <a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='contact.php'?'active':'';?>">
      Контакты
    </a>
    <a href="news.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='news.php'?'active':'';?>">
      Новости
    </a>
  </nav>
</header>

<section class="content">
  <?php if (!empty($message)): ?>
    <p class="message"><?php echo $message; ?></p>
  <?php endif; ?>

  <?php if ($_SESSION['role'] === 'admin'): ?>
    <!-- Вкладки для admin -->
    <div class="tabs">
      <a href="pokazaniya.php?tab=individual" 
         class="<?php echo ($tab==='individual')?'active-tab':'';?>">
         Индивидуальные счётчики
      </a>
      <a href="pokazaniya.php?tab=common" 
         class="<?php echo ($tab==='common')?'active-tab':'';?>">
         Общедомовые счётчики
      </a>
    </div>

    <?php if ($tab==='common'): ?>
      <!-- Общедомовые счётчики -->
      <form method="GET" class="search-form" action="pokazaniya.php">
        <input type="hidden" name="tab" value="common">
        <input type="text" name="searchCommon" placeholder="Поиск по сер. номеру или адресу"
               value="<?php echo htmlspecialchars($searchC ?? ''); ?>">
        <button type="submit">Найти</button>
      </form>
      <h2>Общедомовые счётчики</h2>
      <?php if (isset($result_common) && $result_common->num_rows>0): ?>
        <table>
          <thead>
            <tr>
              <th>Сер. номер</th>
              <th>Тип ресурса</th>
              <th>Дата установки</th>
              <th>Дата поверки</th>
              <th>Адрес</th>
              <th>Последнее показание</th>
              <th>Дата</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
            <?php while($row=$result_common->fetch_assoc()): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['meter_serial_number']);?></td>
                <td><?php echo htmlspecialchars($row['resource_type']);?></td>
                <td><?php echo htmlspecialchars($row['installation_date']);?></td>
                <td><?php echo htmlspecialchars($row['last_check']);?></td>
                <td>
                  <?php
                    // Вывод адреса
                    echo htmlspecialchars($row['region']) . ", " .
                         htmlspecialchars($row['city']) . ", " .
                         htmlspecialchars($row['street']) . " " .
                         htmlspecialchars($row['home']);
                    if (!empty($row['corpus'])) {
                        echo " корп." . htmlspecialchars($row['corpus']);
                    }
                    if (!empty($row['flat_number'])) {
                        echo ", кв." . htmlspecialchars($row['flat_number']);
                    }
                  ?>
                </td>
                <td>
                  <?php echo ($row['last_reading']!==null)
                    ? htmlspecialchars($row['last_reading'])
                    : 'Нет данных';?>
                </td>
                <td>
                  <?php echo ($row['last_reading_date']!==null)
                    ? htmlspecialchars($row['last_reading_date'])
                    : 'Нет данных';?>
                </td>
                <td>
                  <!-- Ввод показаний (admin) -->
                  <form method="POST" style="display:inline-block;">
                    <input type="hidden" name="meter_id" value="<?php echo $row['meter_id'];?>">
                    <input type="number" name="value" step="0.01" placeholder="Показ." style="width:80px;">
                    <button type="submit" name="submit_reading">OK</button>
                  </form>
                  <br>
                  <!-- Мягкое удаление -->
                  <a class="delete-link"
                     href="?tab=common&soft_delete_meter_id=<?php echo $row['meter_id'];?>"
                     onclick="return confirm('Пометить счётчик как удалённый?');">
                     Удалить
                  </a>
                  <!-- Редактирование -->
                  <div class="edit-meter-form" style="margin-top:5px;">
                    <form method="POST">
                      <input type="hidden" name="meter_id" value="<?php echo $row['meter_id'];?>">
                      <label>Сер. номер</label>
                      <input type="text" name="edit_serial"
                             value="<?php echo htmlspecialchars($row['meter_serial_number']);?>" required>
                      <label>Тип ресурса</label>
                      <select name="edit_resource_type">
                        <?php
                          $types = ['ХВС','ГВС','Электричество','Отопление','Газ'];
                          foreach($types as $t) {
                            $sel=($t===$row['resource_type'])?'selected':'';
                            echo "<option value=\"$t\" $sel>$t</option>";
                          }
                        ?>
                      </select>
                      <label>Дата установки</label>
                      <input type="date" name="edit_installation_date"
                             value="<?php echo htmlspecialchars($row['installation_date']);?>" required>
                      <label>Дата поверки</label>
                      <input type="date" name="edit_last_check"
                             value="<?php echo htmlspecialchars($row['last_check']);?>" required>
                      <button type="submit" name="edit_common_meter">Сохранить</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endwhile;?>
          </tbody>
        </table>
      <?php else: ?>
        <p>Общедомовые счётчики не найдены.</p>
      <?php endif; ?>

    <?php else: ?>
      <!-- Индивидуальные счётчики (список жильцов) -->
      <h2>Индивидуальные счётчики (Жильцы)</h2>
      <form method="GET" class="search-form" action="pokazaniya.php">
        <input type="hidden" name="tab" value="individual">
        <input type="text" name="searchInd" placeholder="Поиск по ФИО, л/с или адресу"
               value="<?php echo htmlspecialchars($searchI ?? ''); ?>">
        <button type="submit">Найти</button>
      </form>

      <?php if (isset($res_users) && $res_users->num_rows>0): ?>
        <table>
          <thead>
            <tr>
              <th>ФИО</th>
              <th>Логин</th>
              <th>Телефон</th>
              <th>Email</th>
              <th>Л/с</th>
              <th>Адрес</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
            <?php while($u=$res_users->fetch_assoc()): ?>
              <tr>
                <td><?php echo htmlspecialchars($u['fullname']);?></td>
                <td><?php echo htmlspecialchars($u['login']);?></td>
                <td><?php echo htmlspecialchars($u['phone']);?></td>
                <td><?php echo htmlspecialchars($u['email']);?></td>
                <td><?php echo htmlspecialchars($u['account_number']);?></td>
                <td><?php echo htmlspecialchars($u['full_address']);?></td>
                <td>
                  <a href="user_meters.php?user_id=<?php echo $u['user_id'];?>" class="info-btn">
                    Информация о счетчиках
                  </a>
                </td>
              </tr>
            <?php endwhile;?>
          </tbody>
        </table>
      <?php else: ?>
        <p>Нет пользователей по вашему запросу.</p>
      <?php endif; ?>

    <?php endif; // end if tab===common ?>
  <?php else: ?>
    <!-- Если role=user (Жилец) => Мои счётчики -->
    <h2>Мои индивидуальные счётчики</h2>
    <p>Показания можно вносить <strong>1 раз в месяц</strong>, <strong>только с 18 по 25 число</strong>.
       Если в текущем месяце уже есть показания, вы не сможете внести повторно.</p>

    <?php if (isset($res_user_meters) && $res_user_meters->num_rows>0): ?>
      <table>
        <thead>
          <tr>
            <th>Сер. номер</th>
            <th>Тип</th>
            <th>Установка</th>
            <th>Поверка</th>
            <th>Адрес</th>
            <th>Последнее показание</th>
            <th>Дата</th>
            <th>Ввести</th>
          </tr>
        </thead>
        <tbody>
          <?php while($mm=$res_user_meters->fetch_assoc()): ?>
            <tr>
              <td><?php echo htmlspecialchars($mm['meter_serial_number']);?></td>
              <td><?php echo htmlspecialchars($mm['resource_type']);?></td>
              <td><?php echo htmlspecialchars($mm['installation_date']);?></td>
              <td><?php echo htmlspecialchars($mm['last_check']);?></td>
              <td>
                <?php
                  echo htmlspecialchars($mm['region']).", ".htmlspecialchars($mm['city']).", ".
                       htmlspecialchars($mm['street'])." ".htmlspecialchars($mm['home']);
                  if (!empty($mm['corpus'])) {
                      echo " корп.".htmlspecialchars($mm['corpus']);
                  }
                  if (!empty($mm['flat_number'])) {
                      echo ", кв.".htmlspecialchars($mm['flat_number']);
                  }
                ?>
              </td>
              <td><?php echo ($mm['last_reading']!==null)
                ? htmlspecialchars($mm['last_reading'])
                : 'Нет данных';?></td>
              <td><?php echo ($mm['last_reading_date']!==null)
                ? htmlspecialchars($mm['last_reading_date'])
                : 'Нет данных';?></td>
              <td>
                <!-- Форма внесения показаний (user) -->
                <form method="POST">
                  <input type="hidden" name="meter_id" value="<?php echo $mm['meter_id'];?>">
                  <input type="number" step="0.01" min="0" name="value" style="width:80px;" required>
                  <button type="submit" name="submit_reading">OK</button>
                </form>
              </td>
            </tr>
          <?php endwhile;?>
        </tbody>
      </table>
    <?php else: ?>
      <p>У вас нет зарегистрированных счётчиков.</p>
    <?php endif; ?>
  <?php endif; // end if role=admin else ?>
</section>

<footer>
  <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>

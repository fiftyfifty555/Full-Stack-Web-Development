<?php
session_start();
require_once 'session_timeout.php';
require_once 'auth_check.php'; // Проверка авторизации

// Доступен только админу
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Доступ запрещён.");
}

$conn = new mysqli('localhost','root','Vk280205+','jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: ".$conn->connect_error);
}

// user_id жильца
if (!isset($_GET['user_id'])) {
    die("Не указан user_id (жильца).");
}
$user_id = (int)$_GET['user_id'];

// Проверяем, что этот пользователь принадлежит текущей УК
$stmt_user = $conn->prepare("
    SELECT u.fullname, u.address_id, a.management_company_id
    FROM users u
    JOIN address a ON u.address_id = a.address_id
    WHERE u.user_id = ?
");
$stmt_user->bind_param("i",$user_id);
$stmt_user->execute();
$res_user = $stmt_user->get_result();
if ($res_user->num_rows === 0) {
    die("Пользователь не найден.");
}
$user_data = $res_user->fetch_assoc();
$stmt_user->close();

if ($user_data['management_company_id'] != $_SESSION['user_id']) {
    die("Пользователь не относится к вашей УК!");
}

$message = "";

// ОБРАБОТКА POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Создать счётчик
    if (isset($_POST['create_meter'])) {
        $serial = trim($_POST['meter_serial_number'] ?? '');
        $r_type = trim($_POST['resource_type'] ?? 'ХВС');
        $inst   = $_POST['installation_date'] ?? date('Y-m-d');
        $chk    = $_POST['last_check'] ?? date('Y-m-d');
        $rsupp  = (int)($_POST['resource_supplier_id'] ?? 1);
        $m_type = trim($_POST['meter_type'] ?? 'индивидуальный');

        // Проверка дат
        if ($chk < $inst) {
            $message = "Ошибка: дата поверки не может быть раньше даты установки!";
        } else {
            // Проверка уникальности серийного номера (не удалённого)
            $sql_ck = "SELECT meter_id FROM meter WHERE meter_serial_number=? AND is_deleted=0";
            $stmt_ck = $conn->prepare($sql_ck);
            $stmt_ck->bind_param("s",$serial);
            $stmt_ck->execute();
            $stmt_ck->store_result();
            if ($stmt_ck->num_rows > 0) {
                $message = "Счётчик с сер. номером '$serial' уже существует!";
            }
            $stmt_ck->close();

            if (empty($message)) {
                // Вставляем
                $ins_sql = "
                  INSERT INTO meter(
                    meter_serial_number, resource_type,
                    installation_date, last_check,
                    address_id, resource_supplier_id, meter_type
                  )
                  VALUES (?,?,?,?,?,?,?)
                ";
                $stmt_ins = $conn->prepare($ins_sql);
                $stmt_ins->bind_param("ssssiis",
                    $serial, $r_type,
                    $inst, $chk,
                    $user_data['address_id'], $rsupp,
                    $m_type
                );
                if ($stmt_ins->execute()) {
                    $message = "Счётчик успешно создан.";
                } else {
                    $message = "Ошибка создания счётчика: ".$stmt_ins->error;
                }
                $stmt_ins->close();
            }
        }
    }
    
    // Редактирование счётчика
    elseif (isset($_POST['edit_meter'])) {
        $meter_id = (int)($_POST['meter_id'] ?? 0);
        $serial   = trim($_POST['edit_serial'] ?? '');
        $r_type   = trim($_POST['edit_resource_type'] ?? 'ХВС');
        $inst     = $_POST['edit_installation_date'] ?? date('Y-m-d');
        $chk      = $_POST['edit_last_check'] ?? date('Y-m-d');

        if ($chk < $inst) {
            $message = "Ошибка: Дата поверки не может быть раньше даты установки!";
        } else {
            // Проверка, что счётчик не удалён
            $chk_sql = "
              SELECT meter_id
              FROM meter
              WHERE meter_id=?
                AND address_id=?
                AND is_deleted=0
            ";
            $stmt_ck = $conn->prepare($chk_sql);
            $stmt_ck->bind_param("ii",$meter_id,$user_data['address_id']);
            $stmt_ck->execute();
            $stmt_ck->store_result();
            if ($stmt_ck->num_rows === 0) {
                $message = "Счётчик не найден или уже удалён.";
            }
            $stmt_ck->close();

            if (empty($message)) {
                // Проверим уникальность серийного номера
                $uq_sql = "
                  SELECT meter_id
                  FROM meter
                  WHERE meter_serial_number=?
                    AND meter_id<>?
                    AND is_deleted=0
                ";
                $stmt_uq = $conn->prepare($uq_sql);
                $stmt_uq->bind_param("si", $serial,$meter_id);
                $stmt_uq->execute();
                $stmt_uq->store_result();
                if ($stmt_uq->num_rows > 0) {
                    $message = "Сер. номер '$serial' уже используется!";
                }
                $stmt_uq->close();
            }

            if (empty($message)) {
                // Обновляем
                $upd_sql = "
                    UPDATE meter
                    SET meter_serial_number=?,
                        resource_type=?,
                        installation_date=?,
                        last_check=?
                    WHERE meter_id=?
                      AND address_id=?
                      AND is_deleted=0
                ";
                $stmt_up = $conn->prepare($upd_sql);
                $stmt_up->bind_param("ssssii",
                    $serial, $r_type,
                    $inst, $chk,
                    $meter_id, $user_data['address_id']
                );
                if ($stmt_up->execute()) {
                    $message = "Данные счётчика обновлены.";
                } else {
                    $message = "Ошибка при обновлении: ".$stmt_up->error;
                }
                $stmt_up->close();
            }
        }
    }
    
    // Внесение показаний
    elseif (isset($_POST['add_reading'])) {
        $meter_id = (int)($_POST['meter_id'] ?? 0);
        $new_val  = (float)($_POST['new_value'] ?? 0);

        // Определяем "текущий период" в формате мм-гггг
        $current_period = date('m-Y');  // например "02-2025"
        
        // 1) Проверяем, есть ли уже счёт за этот месяц для данного счётчика
        //    Сравниваем billing_period с "02-2025"
        $sql_chBill = "
          SELECT bill_id
          FROM bills
          WHERE meter_id = ?
            AND user_id = ?
            AND billing_period = ?
          LIMIT 1
        ";
        $stmt_b = $conn->prepare($sql_chBill);
        $stmt_b->bind_param("iis", $meter_id, $user_id, $current_period);
        $stmt_b->execute();
        $stmt_b->store_result();

        if ($stmt_b->num_rows > 0) {
            $message = "Нельзя внести (изменить) показания: счёт за текущий период уже сформирован!";
        }
        $stmt_b->close();

        // 2) Проверяем, не меньше ли новое показание максимального из предшествующих месяцев
        if (empty($message)) {
            // Возьмём начало текущего месяца (в формате YYYY-MM-01),
            // чтобы все показания до этой даты считались "предыдущими".
            $year = date('Y');    // "2025"
            $month= date('m');    // "02"
            $start_of_this_month = $year."-".$month."-01"; // "2025-02-01"

            $sql_prev = "
              SELECT MAX(value) AS max_val
              FROM meter_readings
              WHERE meter_id=?
                AND user_id=?
                AND data < ?
            ";
            $stmt_prev = $conn->prepare($sql_prev);
            $stmt_prev->bind_param("iis", $meter_id, $user_id, $start_of_this_month);
            $stmt_prev->execute();
            $res_prev = $stmt_prev->get_result();
            $row_prev = $res_prev->fetch_assoc();
            $prev_max_val = $row_prev['max_val'] ?? null;
            $stmt_prev->close();

            if (!is_null($prev_max_val) && $new_val < $prev_max_val) {
                $message = "Ошибка: новое показание ($new_val) не может быть меньше предыдущих ($prev_max_val).";
            }
        }

        // 3) Если всё ок, вставляем новое показание (удалив старое за "этот" месяц)
        //    Считаем "этот" месяц => все показания, у которых data >= start_of_this_month
        //    и data < start_of_next_month, но проще по условию (DATE_FORMAT(...,'%m-%Y')=...)
        if (empty($message)) {
            // Для согласованности с нашим "текущим месяцем" (mm-YYYY),
            // мы можем просто удалить то показание, у которого MONTH(data) = текущий месяц 
            // и YEAR(data) = текущий год. 
            // Но проще использовать границы дат.
            $start_of_this_month_dt = strtotime($start_of_this_month); // 2025-02-01
            $start_of_next_month_dt = strtotime("+1 month", $start_of_this_month_dt);
            $start_of_next_month = date('Y-m-d', $start_of_next_month_dt); // 2025-03-01

            $del_sql = "
                DELETE FROM meter_readings
                WHERE meter_id = ?
                  AND user_id = ?
                  AND data >= ?
                  AND data < ?
            ";
            $stmt_del = $conn->prepare($del_sql);
            $stmt_del->bind_param("isss", $meter_id, $user_id, $start_of_this_month, $start_of_next_month);
            // Обратите внимание, тут типы: (i, s, s, s)? На самом деле у нас 4 переменных:
            // meter_id (int), user_id (int), start_of_this_month(string), start_of_next_month(string)
            // Значит bind_param("iiss", ...)  — поправим:
            $stmt_del->bind_param("iiss", $meter_id, $user_id, $start_of_this_month, $start_of_next_month);
            $stmt_del->execute();
            $stmt_del->close();

            // Вставляем новое (дату можно поставить вручную как текущую, или как-то иначе):
            $ins_sql = "
              INSERT INTO meter_readings(meter_id, user_id, value)
              VALUES (?,?,?)
            ";
            $stmt_ins = $conn->prepare($ins_sql);
            $stmt_ins->bind_param("iid", $meter_id, $user_id, $new_val);
            if ($stmt_ins->execute()) {
                $message = "Новое показание за период $current_period внесено.";
            } else {
                $message = "Ошибка при внесении: ".$stmt_ins->error;
            }
            $stmt_ins->close();
        }
    }

    // После обработки запроса — редирект (PRG)
    header("Location: user_meters.php?user_id=$user_id&message=".urlencode($message));
    exit();
}

// GET message
if (isset($_GET['message']) && $_GET['message'] !== '') {
    $message = $_GET['message'];
}

// Мягкое удаление счётчика (индивидуальные)
if (isset($_GET['delete_meter_id'])) {
    $del_id = (int)$_GET['delete_meter_id'];
    $check_sql = "
      SELECT meter_id
      FROM meter
      WHERE meter_id=?
        AND address_id=?
        AND is_deleted=0
    ";
    $stmt_ch = $conn->prepare($check_sql);
    $stmt_ch->bind_param("ii",$del_id,$user_data['address_id']);
    $stmt_ch->execute();
    $stmt_ch->store_result();
    if ($stmt_ch->num_rows > 0) {
        $upd = $conn->prepare("UPDATE meter SET is_deleted=1 WHERE meter_id=?");
        $upd->bind_param("i",$del_id);
        if ($upd->execute()) {
            $message = "Счётчик мягко удалён.";
        } else {
            $message = "Ошибка удаления: ".$upd->error;
        }
        $upd->close();
    }
    $stmt_ch->close();

    header("Location: user_meters.php?user_id=$user_id&message=".urlencode($message));
    exit();
}

// Список индивидуальных счётчиков пользователя
$sql_meters = "
  SELECT
    m.meter_id,
    m.meter_serial_number,
    m.resource_type,
    m.installation_date,
    m.last_check,
    m.meter_type
  FROM meter m
  WHERE m.address_id=?
    AND m.meter_type='индивидуальный'
    AND m.is_deleted=0
";
$stmt_m = $conn->prepare($sql_meters);
$stmt_m->bind_param("i",$user_data['address_id']);
$stmt_m->execute();
$res_meters = $stmt_m->get_result();
$stmt_m->close();

// Список поставщиков (для формы создания)
$sql_sup = "SELECT resource_supplier_id, resource_supplier_name FROM resource_supplier";
$r_sup = $conn->query($sql_sup);
$suppliers = [];
while($rw = $r_sup->fetch_assoc()) {
    $suppliers[] = $rw;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Счётчики пользователя</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: linear-gradient(135deg, #6dd5ed, #2193b0);
      color: #fff;
      margin: 0; 
      padding: 0;
      min-height: 100vh; 
      display: flex; 
      flex-direction: column;
    }
    header, footer {
      text-align: center;
      background: rgba(0,0,0,0.6);
      padding: 20px;
    }
    .content {
      padding: 100px 20px 50px; 
      flex: 1; 
      text-align: center;
    }
    .big-btn {
      display: inline-block;
      background: #2193b0; 
      color: #fff;
      padding: 12px 28px;
      border-radius: 25px;
      text-decoration: none;
      transition: 0.3s;
      font-size: 1.3em;
      margin: 15px;
    }
    .big-btn:hover {
      background: #17677d;
    }
    .message {
      color: yellow; 
      margin-bottom: 15px; 
      font-weight: bold;
    }
    table {
      width: 80%; 
      margin: 20px auto; 
      border-collapse: collapse;
      background: #fff; 
      color: #000;
    }
    table th, table td {
      padding: 15px; 
      border: 1px solid #ccc; 
      text-align: center;
    }
    table th {
      background: #2193b0; 
      color: #fff;
    }
    .form-container {
      width: 80%;
      margin: 0 auto; 
      background: rgba(255,255,255,0.8);
      color: #000; 
      padding: 30px; 
      border-radius: 10px; 
      text-align: left;
    }
    .form-container label {
      display: block; 
      margin: 10px 0 5px 0; 
      font-weight: bold;
    }
    .form-container input, .form-container select {
      display: block; 
      width: 70%; 
      padding: 10px; 
      margin-bottom: 15px; 
      border-radius: 5px;
      border: 1px solid #ccc;
      font-size: 1em;
    }
    .form-container button {
      padding: 12px 28px; 
      background: #2193b0; 
      color: #fff; 
      border: none;
      border-radius: 25px; 
      cursor: pointer; 
      font-size: 1.1em;
    }
    .form-container button:hover {
      background: #17677d;
    }
    .delete-link {
      color: red; 
      text-decoration: none; 
      font-weight: bold;
    }
    .edit-meter-form {
      margin-top: 10px; 
      background: rgba(0,0,0,0.2); 
      padding: 15px; 
      border-radius: 10px; 
      display: inline-block; 
      text-align: left;
    }
    .edit-meter-form label {
      display: block;
      margin: 5px 0;
      color: #fff;
      font-weight: bold;
    }
    .edit-meter-form input[type="text"], 
    .edit-meter-form input[type="date"],
    .edit-meter-form select {
      width: 250px; 
      margin-bottom: 10px; 
      padding: 6px; 
      border-radius: 5px; 
      border: 1px solid #ccc; 
      font-size: 1em;
    }
    .reading-block {
      background: rgba(0,0,0,0.2);
      padding: 10px;
      margin-top: 5px;
      border-radius: 10px;
    }
    .reading-block table {
      width: 60%;
      margin: 0 auto;
      background: #f7f7f7;
      color: #000;
      font-size: 0.9em;
    }
    .reading-block table th {
      background: #ddd;
      font-weight: bold;
    }
  </style>
</head>
<body>
<header>
  <h1>Счётчики пользователя: <?php echo htmlspecialchars($user_data['fullname']);?></h1>
</header>

<div class="content">
  <a href="pokazaniya.php?tab=individual" class="big-btn">Назад</a>

  <?php if(!empty($message)):?>
    <p class="message"><?php echo $message;?></p>
  <?php endif;?>

  <!-- Форма создания счётчика -->
  <div class="form-container" style="margin-top:20px;">
    <h2 style="text-align:center;">Создать новый счётчик</h2>
    <form method="POST">
      <label>Серийный номер:</label>
      <input type="text" name="meter_serial_number" required maxlength="10">

      <label>Тип ресурса:</label>
      <select name="resource_type">
        <option value="ХВС">ХВС</option>
        <option value="ГВС">ГВС</option>
        <option value="Электричество">Электричество</option>
        <option value="Отопление">Отопление</option>
        <option value="Газ">Газ</option>
      </select>

      <label>Дата установки:</label>
      <input type="date" name="installation_date" required>

      <label>Дата поверки:</label>
      <input type="date" name="last_check" required>

      <label>Ресурсоснабжающая организация:</label>
      <select name="resource_supplier_id">
        <?php foreach($suppliers as $s):?>
          <option value="<?php echo $s['resource_supplier_id'];?>">
            <?php echo htmlspecialchars($s['resource_supplier_name']);?>
          </option>
        <?php endforeach;?>
      </select>

      <label>Тип счётчика:</label>
      <select name="meter_type">
        <option value="индивидуальный">Индивидуальный</option>
        <option value="общедомовой">Общедомовой</option>
      </select>

      <button type="submit" name="create_meter">Создать счётчик</button>
    </form>
  </div>

  <h2 style="margin-top:40px;">Существующие счётчики (индивидуальные)</h2>
  <?php if($res_meters->num_rows > 0): ?>
    <table>
      <thead>
        <tr>
          <th>Серийный номер</th>
          <th>Тип ресурса</th>
          <th>Установка</th>
          <th>Поверка</th>
          <th>Тип</th>
          <th>Удалить</th>
        </tr>
      </thead>
      <tbody>
        <?php while($m = $res_meters->fetch_assoc()): ?>
          <tr>
            <td><?php echo htmlspecialchars($m['meter_serial_number']);?></td>
            <td><?php echo htmlspecialchars($m['resource_type']);?></td>
            <td><?php echo htmlspecialchars($m['installation_date']);?></td>
            <td><?php echo htmlspecialchars($m['last_check']);?></td>
            <td><?php echo htmlspecialchars($m['meter_type']);?></td>
            <td>
              <a class="delete-link"
                 href="?user_id=<?php echo $user_id;?>&delete_meter_id=<?php echo $m['meter_id'];?>"
                 onclick="return confirm('Пометить счётчик как удалённый?');">
                Удалить
              </a>
            </td>
          </tr>
          <!-- Форма редактирования -->
          <tr>
            <td colspan="6">
              <div class="edit-meter-form">
                <form method="POST">
                  <h3 style="margin-top:0;">Редактировать счётчик</h3>
                  <input type="hidden" name="meter_id" value="<?php echo $m['meter_id'];?>">
                  <label>Серийный номер</label>
                  <input type="text" name="edit_serial" value="<?php echo htmlspecialchars($m['meter_serial_number']);?>" required>
                  <label>Тип ресурса</label>
                  <select name="edit_resource_type">
                    <?php
                      $allTypes = ['ХВС','ГВС','Электричество','Отопление','Газ'];
                      foreach($allTypes as $t) {
                        $sel = ($m['resource_type'] === $t) ? 'selected' : '';
                        echo "<option value=\"$t\" $sel>$t</option>";
                      }
                    ?>
                  </select>
                  <label>Дата установки</label>
                  <input type="date" name="edit_installation_date" value="<?php echo $m['installation_date'];?>" required>
                  <label>Дата поверки</label>
                  <input type="date" name="edit_last_check" value="<?php echo $m['last_check'];?>" required>
                  <button type="submit" name="edit_meter">Сохранить</button>
                </form>
              </div>
            </td>
          </tr>
          <!-- Последние 5 показаний -->
          <tr>
            <td colspan="6">
              <div class="reading-block">
                <h3>Последние 5 показаний</h3>
                <?php
                  // Получаем последние 5 показаний для конкретного счётчика (индив.) и этого user_id
                  $sql_5 = "
                    SELECT value, data
                    FROM meter_readings
                    WHERE meter_id=?
                      AND user_id=?
                    ORDER BY data DESC
                    LIMIT 5
                  ";
                  $stmt_5 = $conn->prepare($sql_5);
                  $stmt_5->bind_param("ii", $m['meter_id'], $user_id);
                  $stmt_5->execute();
                  $res_5 = $stmt_5->get_result();
                  if ($res_5->num_rows > 0):
                ?>
                  <table>
                    <thead>
                      <tr>
                        <th>Показание</th>
                        <th>Дата внесения</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php while($r5 = $res_5->fetch_assoc()): ?>
                        <tr>
                          <td><?php echo htmlspecialchars($r5['value']);?></td>
                          <td><?php echo htmlspecialchars($r5['data']);?></td>
                        </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <em>Нет данных о показаниях.</em>
                <?php endif;
                  $stmt_5->close();
                ?>
              </div>
            </td>
          </tr>
          <!-- Форма внесения (или замены) показаний за текущий месяц -->
          <tr>
            <td colspan="6">
              <div style="margin-top:10px; background:rgba(0,0,0,0.2);padding:15px;border-radius:10px;display:inline-block;">
                <form method="POST">
                  <h3 style="margin-top:0;">Внести (заменить) показания за текущий месяц</h3>
                  <input type="hidden" name="meter_id" value="<?php echo $m['meter_id'];?>">
                  <label>Новое значение:</label>
                  <input type="number" step="0.01" name="new_value" min="0" required>
                  <button type="submit" name="add_reading">Сохранить</button>
                  <p style="font-size:0.85em;color:#fff;margin-top:5px;">
                    * Если было показание за этот же период (<?php echo date('m-Y'); ?>), оно будет удалено и заменено.
                  </p>
                </form>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>Нет индивидуальных счётчиков у данного пользователя (или все удалены).</p>
  <?php endif;?>
</div>

<footer>
  <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>

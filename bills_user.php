<?php
session_start();
require_once 'auth_check.php';
require_once 'session_timeout.php'; // если требуется проверка 2-минутной сессии

// Подключение к БД
$conn = new mysqli('localhost:3306', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: ".$conn->connect_error);
}

// Кнопка "Выйти"
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time()-3600, '/');
    header("Location: index.php");
    exit();
}

// user_id (жителя)
if (!isset($_GET['user_id'])) {
    die("Не указан user_id (пользователя).");
}
$view_user_id = (int)$_GET['user_id'];

// Проверяем, УК ли
$current_user_id = $_SESSION['user_id'];
$stmt_check_mc = $conn->prepare("
    SELECT management_company_id
    FROM management_company
    WHERE management_company_id=?
");
$stmt_check_mc->bind_param("i", $current_user_id);
$stmt_check_mc->execute();
$stmt_check_mc->store_result();
$is_management_company = ($stmt_check_mc->num_rows>0);
$stmt_check_mc->close();

// Если не УК и (user_id != текущий), выдаём ошибку
if (!$is_management_company && $current_user_id != $view_user_id) {
    die("Недостаточно прав для просмотра чужих начислений (user_id).");
}

// Если УК — узнаём название УК
$mc_name = "";
if ($is_management_company) {
    $stmt_mc = $conn->prepare("
        SELECT management_company_name
        FROM management_company
        WHERE management_company_id=?
    ");
    $stmt_mc->bind_param("i", $current_user_id);
    $stmt_mc->execute();
    $stmt_mc->bind_result($mc_name);
    $stmt_mc->fetch();
    $stmt_mc->close();
}

// ФИО пользователя
$stmt_user = $conn->prepare("
    SELECT fullname
    FROM users
    WHERE user_id=?
");
$stmt_user->bind_param("i", $view_user_id);
$stmt_user->execute();
$stmt_user->bind_result($selected_user_name);
$stmt_user->fetch();
$stmt_user->close();

// Параметр периода (мм-гггг)
$selected_billing_period = isset($_GET['billing_period']) ? trim($_GET['billing_period']) : "";

// Сообщение
$message = "";
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Обновляем last_update для НЕоплаченных счетов этого пользователя
$upd = $conn->prepare("
    UPDATE bills
    SET last_update=NOW()
    WHERE user_id=?
      AND payment_date IS NULL
");
$upd->bind_param("i", $view_user_id);
$upd->execute();
$upd->close();

// Список начислений
$sql_bills = "
    SELECT
      b.bill_id,
      b.tariff_id,
      b.consumption,
      b.receiving_date,
      b.deadline_date,
      b.meter_id,
      b.cost,
      b.penalty,
      b.total_amount,
      b.billing_period,
      t.service_type,
      m.meter_serial_number,
      COALESCE(rs.resource_supplier_name, mc.management_company_name) AS provider_name
    FROM bills b
    LEFT JOIN tariffs t ON b.tariff_id = t.tariff_id
    LEFT JOIN meter m ON b.meter_id = m.meter_id
    LEFT JOIN resource_supplier rs ON t.resource_supplier_id = rs.resource_supplier_id
    LEFT JOIN management_company mc ON t.management_company_id = mc.management_company_id
    WHERE b.user_id=?
";
$bind_types = "i";
$bind_vals = [$view_user_id];

// Фильтр по периоду
if ($selected_billing_period !== "") {
    $sql_bills .= " AND b.billing_period=? ";
    $bind_types .= "s";
    $bind_vals[] = $selected_billing_period;
}

// УК видит только НЕоплаченные
if ($is_management_company) {
    $sql_bills .= " AND b.payment_date IS NULL ";
}
$sql_bills .= " ORDER BY b.billing_period DESC, b.bill_id DESC";

$stmt_b = $conn->prepare($sql_bills);
$stmt_b->bind_param($bind_types, ...$bind_vals);
$stmt_b->execute();
$res_b = $stmt_b->get_result();
$bills = [];
while ($row = $res_b->fetch_assoc()) {
    $bills[] = $row;
}
$stmt_b->close();

/** Удаление начисления (только УК) **/
if (isset($_POST['delete_bill']) && $is_management_company) {
    $del_id = (int)$_POST['bill_id'];
    $st_del = $conn->prepare("DELETE FROM bills WHERE bill_id=?");
    $st_del->bind_param("i", $del_id);
    if ($st_del->execute()) {
        $m = "Начисление #$del_id удалено.";
    } else {
        $m = "Ошибка при удалении: ".$st_del->error;
    }
    $st_del->close();
    header("Location: bills_user.php?user_id=$view_user_id&billing_period=$selected_billing_period&msg=".urlencode($m));
    exit();
}

/** Сценарий блокировки: wrong / correct **/
$scenario = isset($_GET['scenario']) ? $_GET['scenario'] : 'wrong';

// Создание нового начисления (доступно только УК)
if (isset($_POST['create_bill']) && $is_management_company) {
    // Проверка периода
    if (!$selected_billing_period) {
        $message = "Сначала выберите период (мм-гггг)!";
    } else {
        if (!preg_match('/^(\d{2})-(\d{4})$/', $selected_billing_period, $mmch)) {
            $message = "Формат периода должен быть мм-гггг!";
        } else {
            $mm = (int)$mmch[1];
            $yyyy = (int)$mmch[2];
            if ($mm<1 || $mm>12) {
                $message = "Месяц должен быть от 01 до 12!";
            }
        }
    }

    // Данные формы
    $tariff_id = (int)($_POST['tariff_id'] ?? 0);
    $meter_id  = !empty($_POST['meter_id']) ? (int)$_POST['meter_id'] : null;

    if (empty($message)) {
        try {
            // Правильный сценарий: блокировка
            if ($scenario==='correct') {
                $conn->query("
                  LOCK TABLES
                    bills WRITE,
                    meter READ,
                    meter_readings WRITE,
                    tariffs READ
                ");
            }

            // 1) Получаем тариф
            $stmt_t = $conn->prepare("
                SELECT service_type, price, normative
                FROM tariffs
                WHERE tariff_id=?
            ");
            $stmt_t->bind_param("i", $tariff_id);
            $stmt_t->execute();
            $stmt_t->bind_result($t_service, $t_price, $t_normative);
            $found_t = $stmt_t->fetch();
            $stmt_t->close();
            if (!$found_t) {
                throw new Exception("Тариф (ID=$tariff_id) не найден!");
            }

            // 2) Проверяем услугу vs счётчик
            $resourceServices = ['ХВС','ГВС','Отопление','Газ','Электроснабжение'];
            $noMeterServices  = ['Вывоз мусора','Фонд капитального ремонта','Содержание жилого помещения'];

            if (in_array($t_service, $resourceServices)) {
                if (!$meter_id) {
                    throw new Exception("Ошибка: для '$t_service' обязательно нужен счётчик!");
                }
                // Проверка счётчика (is_deleted)
                $st_m = $conn->prepare("
                    SELECT meter_serial_number, is_deleted
                    FROM meter
                    WHERE meter_id=?
                ");
                $st_m->bind_param("i", $meter_id);
                $st_m->execute();
                $rw_m = $st_m->get_result()->fetch_assoc();
                $st_m->close();
                if (!$rw_m) {
                    throw new Exception("Счётчик (ID=$meter_id) не найден!");
                } elseif ($rw_m['is_deleted']==1) {
                    throw new Exception("Счётчик (сер.№ ".$rw_m['meter_serial_number'].") удалён!");
                }
            } elseif (in_array($t_service, $noMeterServices)) {
                if ($meter_id) {
                    throw new Exception("Ошибка: для '$t_service' нельзя выбирать счётчик!");
                }
            }

            // 3) Проверяем дубликат
            if ($meter_id) {
                $chk_sql = "
                  SELECT COUNT(*) 
                  FROM bills
                  WHERE user_id=?
                    AND billing_period=?
                    AND meter_id=?
                ";
                $st_chk = $conn->prepare($chk_sql);
                $st_chk->bind_param("isi", $view_user_id, $selected_billing_period, $meter_id);
            } else {
                $chk_sql = "
                  SELECT COUNT(*)
                  FROM bills
                  WHERE user_id=?
                    AND billing_period=?
                    AND meter_id IS NULL
                    AND tariff_id=?
                ";
                $st_chk = $conn->prepare($chk_sql);
                $st_chk->bind_param("isi", $view_user_id, $selected_billing_period, $tariff_id);
            }
            $st_chk->execute();
            $st_chk->bind_result($hasDup);
            $st_chk->fetch();
            $st_chk->close();

            // имитируем задержку
            sleep(10);

            if ($hasDup>0) {
                throw new Exception("Уже есть начисление за $selected_billing_period по этому тарифу/счётчику!");
            }

            // 4) Вычисляем потребление / cost
            $consumption = 0.0;
            $cost = 0.0;

            // Если услуга без счётчика => можно (для примера) взять норматив * кол-во жильцов, 
            // или "square", как в вашем исходном коде. Ниже — упрощённо:
            if (in_array($t_service, $noMeterServices)) {
                // Получим площадь/жильцов (упрощённо)
                $st_addr = $conn->prepare("
                  SELECT a.square, a.residents_number
                  FROM address a
                  JOIN users u ON u.address_id=a.address_id
                  WHERE u.user_id=?
                ");
                $st_addr->bind_param("i", $view_user_id);
                $st_addr->execute();
                $st_addr->bind_result($addr_sq, $addr_res);
                $st_addr->fetch();
                $st_addr->close();

                if ($t_service==='Вывоз мусора') {
                    $consumption = $addr_res;
                } elseif ($t_service==='Фонд капитального ремонта') {
                    $consumption = $addr_sq;
                } elseif ($t_service==='Содержание жилого помещения') {
                    $consumption = $addr_sq;
                }
                $cost = $consumption * $t_price;
            }
            else {
                // Услуга со счётчиком => читаем показания
                if ($meter_id) {
                    // Определяем начало текущего периода
                    list($pm, $py) = explode('-', $selected_billing_period);
                    $startThis = date('Y-m-01', strtotime("$py-$pm-01"));
                    // Начало след. месяца
                    $startNext = date('Y-m-01', strtotime($startThis.' +1 month'));

                    // Получим последнее показание перед startNext (конец периода)
                    $stmt_cur = $conn->prepare("
                        SELECT value
                        FROM meter_readings
                        WHERE meter_id=?
                          AND user_id=?
                          AND data < ?
                        ORDER BY data DESC
                        LIMIT 1
                    ");
                    $stmt_cur->bind_param("iis", $meter_id, $view_user_id, $startNext);
                    $stmt_cur->execute();
                    $stmt_cur->bind_result($cval);
                    $found_cur = $stmt_cur->fetch();
                    $stmt_cur->close();
                    $curr_val = $found_cur? floatval($cval) : 0.0;

                    // Предыдущее
                    $stmt_prev = $conn->prepare("
                        SELECT value
                        FROM meter_readings
                        WHERE meter_id=?
                          AND user_id=?
                          AND data < ?
                        ORDER BY data DESC
                        LIMIT 1
                    ");
                    $stmt_prev->bind_param("iis", $meter_id, $view_user_id, $startThis);
                    $stmt_prev->execute();
                    $stmt_prev->bind_result($pval);
                    $found_prev = $stmt_prev->fetch();
                    $stmt_prev->close();
                    $prev_val = $found_prev? floatval($pval) : 0.0;

                    $calc = $curr_val - $prev_val;
                    if ($calc<0) {
                        $calc=0;
                    }
                    // Если нет текущего показания => использовать норматив?
                    if (!$found_cur && !empty($t_normative)) {
                        // Простейший вариант: consumption = t_normative
                        $calc = floatval($t_normative);
                    }

                    $consumption = $calc;
                    $cost = $consumption * $t_price;
                }
            }

            // 5) Вставляем запись в bills
            $ins_sql = "
              INSERT INTO bills(
                tariff_id, consumption,
                receiving_date, payment_date,
                user_id, meter_id, cost,
                billing_period, last_update
              )
              VALUES(?, ?, NOW(), NULL, ?, ?, ?, ?, NOW())
            ";
            $stm_ins = $conn->prepare($ins_sql);
            $stm_ins->bind_param("ddiids",
               $tariff_id,
               $consumption,
               $view_user_id,
               $meter_id,
               $cost,
               $selected_billing_period
            );
            if (!$stm_ins->execute()) {
                throw new Exception("Ошибка вставки: ".$stm_ins->error);
            }
            $stm_ins->close();

            $message = "Начисление успешно создано (scenario=$scenario). Потребление=$consumption, cost=$cost";

        } catch(Exception $ex) {
            $message = "Ошибка: ".$ex->getMessage();
        } finally {
            // Разблокировка
            if ($scenario==='correct') {
                $conn->query("UNLOCK TABLES");
            }
        }
    }
    // Перенаправление
    header("Location: bills_user.php?user_id={$view_user_id}&billing_period={$selected_billing_period}&msg=".urlencode($message)."&scenario=".urlencode($scenario));
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Начисления пользователя</title>
  <style>
    body {
      font-family: Arial,sans-serif;
      background: linear-gradient(135deg, #6dd5ed, #2193b0);
      color: #fff; margin:0; padding:0;
    }
    header, footer {
      text-align:center; background:rgba(0,0,0,0.6); padding:20px;
    }
    nav {
      display:flex; justify-content:center; gap:20px; margin-top:15px;
    }
    nav a {
      color:#fff; text-decoration:none; font-size:1.2em;
      padding:10px 20px; border:2px solid #fff; border-radius:25px;
      transition:0.3s;
    }
    nav a:hover, nav a.active {
      background:#fff; color:#2193b0;
    }
    .logout-btn {
      position:absolute; top:20px; right:20px;
      background:red; color:#fff; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;
    }
    .logout-btn:hover { background:darkred; }
    .content { padding:20px; text-align:center; }
    .bills-container {
      background:rgba(255,255,255,0.9); color:#000;
      padding:20px; margin:20px auto; border-radius:10px;
      max-width:1500px; width:100%;
    }
    table {
      width:100%; border-collapse:collapse; margin-top:15px; overflow-x:auto;
    }
    th, td {
      border:1px solid #ccc; padding:8px; background:#fff; color:#000;
    }
    th {
      background:#2193b0; color:#fff;
    }
    .message { color:yellow; font-weight:bold; }
    .create-bill-form {
      background:rgba(255,255,255,0.8); padding:15px; border-radius:10px; 
      margin-top:20px; display:inline-block; text-align:left; max-width:600px;
    }
    .create-bill-form input, .create-bill-form select {
      width:90%; padding:8px; margin:8px 0; 
      border-radius:5px; border:1px solid #ccc;
    }
    .create-bill-form button {
      padding:10px 20px; background:#2193b0; color:#fff; 
      border:none; border-radius:5px; cursor:pointer;
    }
    .create-bill-form button:hover {
      background:#1a7a8f;
    }
    .scenario-links {
      margin-bottom:15px;
    }
    .scenario-links a {
      color:#fff; text-decoration:none; border:1px solid #fff;
      padding:6px 12px; border-radius:5px; margin:0 8px;
    }
    .scenario-links a:hover {
      background:#fff; color:#2193b0;
    }
  </style>
</head>
<body>
<header>
  <h1>Начисления пользователя</h1>
  <form method="POST">
    <button type="submit" name="logout" class="logout-btn">Выйти</button>
  </form>
  <nav>
    <a href="admin.php">Главная</a>
    <a href="tariffs.php">Тарифы</a>
    <a href="pokazaniya.php">Показания</a>
    <a href="bills.php">Начисления</a>
    <a href="payments.php">Платежи</a>
    <a href="contact.php">Контакты</a>
    <a href="news.php">Новости</a>
  </nav>
</header>

<section class="content">
  <?php if ($is_management_company): ?>
    <h2>УК: <?php echo htmlspecialchars($mc_name);?> — Начисления пользователя: <?php echo htmlspecialchars($selected_user_name);?></h2>
    <div class="scenario-links">
       <span>Сценарий блокировки:</span>
       <a href="?user_id=<?php echo $view_user_id;?>&billing_period=<?php echo urlencode($selected_billing_period);?>&scenario=wrong">
         Неправильный (без блокировок)
       </a>
       <a href="?user_id=<?php echo $view_user_id;?>&billing_period=<?php echo urlencode($selected_billing_period);?>&scenario=correct">
         Правильный (LOCK TABLES)
       </a>
       <br><small style="color:#ccc;">(Текущий: <?php echo htmlspecialchars($scenario);?>)</small>
    </div>
  <?php else: ?>
    <h2>Мои начисления — <?php echo htmlspecialchars($selected_user_name);?></h2>
  <?php endif;?>

  <div class="bills-container">
    <!-- Форма выбора периода -->
    <form method="GET" style="margin-bottom:20px;">
      <input type="hidden" name="user_id" value="<?php echo $view_user_id;?>">
      <label>Период (мм-гггг):</label>
      <input type="text" name="billing_period"
             placeholder="Например: 01-2025"
             value="<?php echo htmlspecialchars($selected_billing_period);?>"
             style="width:120px;">
      <button type="submit">Показать</button>
      <?php if($is_management_company):?>
         <!-- Сохраняем scenario, чтоб при смене периода не сбрасывалось -->
         <input type="hidden" name="scenario" value="<?php echo htmlspecialchars($scenario);?>">
      <?php endif;?>
    </form>

    <?php if($message):?>
      <p class="message"><?php echo $message;?></p>
    <?php endif;?>

    <?php if(!empty($bills)): ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Услуга</th>
            <th>Поставщик/УК</th>
            <th>Период</th>
            <th>Потребление</th>
            <th>Дата выставления</th>
            <th>Срок оплаты</th>
            <th>Счётчик</th>
            <th>Начислено</th>
            <th>Пени</th>
            <th>Итого</th>
            <?php if($is_management_company):?>
              <th>Удалить</th>
            <?php endif;?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($bills as $b): ?>
            <tr>
              <td><?php echo $b['bill_id'];?></td>
              <td><?php echo htmlspecialchars($b['service_type']);?></td>
              <td><?php echo htmlspecialchars($b['provider_name']);?></td>
              <td><?php echo htmlspecialchars($b['billing_period']);?></td>
              <td><?php echo htmlspecialchars($b['consumption']);?></td>
              <td><?php echo htmlspecialchars($b['receiving_date']);?></td>
              <td><?php echo $b['deadline_date']?: '—';?></td>
              <td><?php echo $b['meter_serial_number']?: '—';?></td>
              <td><?php echo htmlspecialchars($b['cost']);?></td>
              <td><?php echo htmlspecialchars($b['penalty']);?></td>
              <td><?php echo htmlspecialchars($b['total_amount']);?></td>
              <?php if($is_management_company):?>
                <td>
                  <form method="POST" onsubmit="return confirm('Удалить начисление #<?php echo $b['bill_id'];?>?');">
                    <input type="hidden" name="bill_id" value="<?php echo $b['bill_id'];?>">
                    <button type="submit" name="delete_bill" style="background:red;color:#fff;">
                      Удалить
                    </button>
                  </form>
                </td>
              <?php endif;?>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    <?php else: ?>
      <p>Нет начислений за указанный период или все уже оплачены.</p>
    <?php endif;?>

    <?php if($is_management_company): ?>
      <!-- Форма создания нового начисления -->
      <?php
      // Подключаемся заново для получения списков (тарифы, счётчики)
      $tariffs = [];
      $meters = [];
      $conn2 = new mysqli('localhost:3306','root','Vk280205+','jkh1');
      if(!$conn2->connect_error) {
          // Узнаём address_id + mc_id
          $stmt_a = $conn2->prepare("
              SELECT a.address_id, a.management_company_id
              FROM address a
              JOIN users u ON u.address_id=a.address_id
              WHERE u.user_id=?
          ");
          $stmt_a->bind_param("i", $view_user_id);
          $stmt_a->execute();
          $stmt_a->bind_result($addr_id,$addr_mc_id);
          $stmt_a->fetch();
          $stmt_a->close();

          // Счётчики
          $st_m = $conn2->prepare("
              SELECT meter_id, meter_serial_number, resource_type, meter_type
              FROM meter
              WHERE address_id=?
                AND is_deleted=0
                AND meter_type='индивидуальный'
          ");
          $st_m->bind_param("i",$addr_id);
          $st_m->execute();
          $r_m = $st_m->get_result();
          while($mr=$r_m->fetch_assoc()){
              $meters[]=$mr;
          }
          $st_m->close();

          // Собираем supplier_ids
          $sup_ids=[];
          $st_sup = $conn2->prepare("
              SELECT DISTINCT resource_supplier_id
              FROM meter
              WHERE address_id=?
                AND is_deleted=0
          ");
          $st_sup->bind_param("i",$addr_id);
          $st_sup->execute();
          $r_sup=$st_sup->get_result();
          while($rw2=$r_sup->fetch_assoc()){
              $sup_ids[]=$rw2['resource_supplier_id'];
          }
          $st_sup->close();

          // Тарифы
          $sql_t="SELECT tariff_id, service_type, price, normative FROM tariffs WHERE";
          $p=[];
          // СЖП (управляющая компания)
          $p[]="(service_type='Содержание жилого помещения' AND management_company_id=$addr_mc_id)";
          // Ресурсные (supplier_ids)
          if(!empty($sup_ids)){
             $list=implode(",",$sup_ids);
             $p[]="(
               service_type IN('Вывоз мусора','Фонд капитального ремонта','ХВС','ГВС','Электроснабжение','Отопление','Газ')
               AND resource_supplier_id IN($list)
             )";
          }
          $sql_t.=" ".implode(" OR ",$p);

          $rt = $conn2->query($sql_t);
          while($tt=$rt->fetch_assoc()){
              $tariffs[]=$tt;
          }
          $rt->close();
          $conn2->close();
      }
      ?>
      <div class="create-bill-form">
         <h3>Создать новое начисление 
             <?php if($selected_billing_period){
                echo "за период: ".htmlspecialchars($selected_billing_period);
             }?>
         </h3>
         <form method="POST">
           <label>Тариф (услуга):</label><br>
           <select name="tariff_id" required>
             <option value="">-- Выберите тариф --</option>
             <?php foreach($tariffs as $t): ?>
                <?php 
                  $srv=htmlspecialchars($t['service_type']);
                  $prc=htmlspecialchars($t['price']);
                ?>
                <option value="<?php echo $t['tariff_id'];?>">
                   <?php echo "$srv (тариф: $prc)";?>
                </option>
             <?php endforeach;?>
           </select><br>

           <p style="font-style: italic; color:#000;">
             *Расход рассчитывается автоматически (по счётчику / площади / нормативу).
           </p>

           <label>Счётчик (для ХВС/ГВС/Газ/Отопление/Электро):</label><br>
           <select name="meter_id">
             <option value="">-- Без счётчика --</option>
             <?php foreach($meters as $m): ?>
               <?php
                 $mid=$m['meter_id'];
                 $msn=htmlspecialchars($m['meter_serial_number']);
                 $mrt=htmlspecialchars($m['resource_type']);
               ?>
               <option value="<?php echo $mid;?>">№<?php echo $msn;?> | <?php echo $mrt;?></option>
             <?php endforeach;?>
           </select><br><br>

           <button type="submit" name="create_bill">Создать начисление</button>
         </form>
         <p style="font-size:0.9em;color:#000;margin-top:8px;">
           Текущий сценарий: <strong><?php echo htmlspecialchars($scenario);?></strong><br>
           <em>При "wrong" — нет LOCK TABLES, возможны дубликаты.</em><br>
           <em>При "correct" — используем LOCK TABLES, второй параллельный запрос увидит дубликат.</em>
         </p>
      </div>
    <?php endif; ?>
  </div>
</section>

<footer>
  <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>
	

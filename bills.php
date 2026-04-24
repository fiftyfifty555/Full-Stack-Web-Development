<?php
session_start();
require_once 'auth_check.php';  // убедитесь, что auth_check.php не «убивает» сессию при AJAX
require_once 'session_timeout.php'; // при необходимости

// Подключение к БД
$conn = new mysqli('localhost:3306', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Кнопка «Выйти»
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: index.php");
    exit();
}

$message = "";

/** 1) Обработка оплаты счёта (для обычного пользователя) **/
if (isset($_POST['pay_bill'])) {
    $bill_id = (int)$_POST['bill_id'];
    $user_id = $_SESSION['user_id']; // предполагается, что auth_check гарантирует наличие user_id

    // Проверим счёт
    $stmt = $conn->prepare("
        SELECT total_amount
        FROM bills
        WHERE bill_id=?
          AND user_id=?
          AND payment_date IS NULL
    ");
    $stmt->bind_param("ii", $bill_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($total_amount);
    $stmt->fetch();
    $stmt->close();

    if ($total_amount !== null) {
        // Проверим баланс
        $stmt = $conn->prepare("SELECT account_balance FROM users WHERE user_id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($acc_balance);
        $stmt->fetch();
        $stmt->close();

        if ($acc_balance >= $total_amount) {
            // Списание
            $new_balance = $acc_balance - $total_amount;
            $stmt_u = $conn->prepare("UPDATE users SET account_balance=? WHERE user_id=?");
            $stmt_u->bind_param("di", $new_balance, $user_id);
            $stmt_u->execute();
            $stmt_u->close();

            // Счёт = оплачен
            $stmt_b = $conn->prepare("UPDATE bills SET payment_date=NOW() WHERE bill_id=?");
            $stmt_b->bind_param("i", $bill_id);
            $stmt_b->execute();
            $stmt_b->close();

            $message = "Счёт #$bill_id успешно оплачен.";
        } else {
            $message = "Недостаточно средств для оплаты.";
        }
    } else {
        $message = "Счёт не найден или уже оплачен.";
    }
}

/** 2) Проверка: УК или нет? **/
$current_user_id = $_SESSION['user_id'];
$stmt_mc = $conn->prepare("
    SELECT management_company_id
    FROM management_company
    WHERE management_company_id=?
");
$stmt_mc->bind_param("i", $current_user_id);
$stmt_mc->execute();
$stmt_mc->store_result();
$is_management_company = ($stmt_mc->num_rows>0);
$stmt_mc->close();

// Если УК, берём название
$mc_name = "";
if ($is_management_company) {
    $stmt_mn = $conn->prepare("
        SELECT management_company_name
        FROM management_company
        WHERE management_company_id=?
    ");
    $stmt_mn->bind_param("i", $current_user_id);
    $stmt_mn->execute();
    $stmt_mn->bind_result($mc_name);
    $stmt_mn->fetch();
    $stmt_mn->close();
}

/*
3) Если ?ajax=1&mode=json => возвращаем JSON со свежими cost, penalty, total_amount
   (чтобы пользователь мог видеть обновление пени и т.д. каждую секунду)
*/
if (isset($_GET['ajax']) && $_GET['ajax']=='1' && isset($_GET['mode']) && $_GET['mode']=='json') {
    header("Content-Type: application/json; charset=utf-8");

    // Если УК => вернём пустой массив (так как УК не нужно обновление)
    if ($is_management_company) {
        echo json_encode([]);
        $conn->close();
        exit();
    }

    // Иначе обновим last_update (для пересчёта пени) и вернём данные
    $upd = $conn->prepare("
        UPDATE bills
        SET last_update=NOW()
        WHERE user_id=?
          AND payment_date IS NULL
    ");
    $upd->bind_param("i", $current_user_id);
    $upd->execute();
    $upd->close();

    // Выбираем поля (bill_id, cost, penalty, total_amount)
    $sql_json = "
        SELECT bill_id, cost, penalty, total_amount
        FROM bills
        WHERE user_id=?
          AND payment_date IS NULL
    ";
    $st_j = $conn->prepare($sql_json);
    $st_j->bind_param("i", $current_user_id);
    $st_j->execute();
    $r_j = $st_j->get_result();

    $data = [];
    while($row=$r_j->fetch_assoc()){
        $data[]=$row;
    }
    $st_j->close();

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $conn->close();
    exit();
}

// Иначе рендерим ПОЛНУЮ страницу
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Начисления</title>
  <style>
  body {
     font-family:Arial,sans-serif;
     background:linear-gradient(135deg,#6dd5ed,#2193b0);
     color:#fff; margin:0; padding:0;
  }
  header,footer {
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
  .message { color:yellow; font-weight:bold; }
  table {
     width:100%; border-collapse:collapse; margin-top:15px; overflow-x:auto;
  }
  th,td {
     border:1px solid #ccc; padding:8px; background:#fff; color:#000;
  }
  th {
     background:#2193b0; color:#fff;
  }
  .container {
     background:rgba(255,255,255,0.9); color:#000;
     padding:20px; margin:20px auto; border-radius:10px;
     max-width:1500px; width:100%;
  }
  .filter-form {
     margin-bottom:20px;
  }
  .filter-form input[type="text"] {
     width:200px; padding:6px; margin-right:10px;
  }
  </style>

  <?php if (!$is_management_company): ?>
  <!-- Скрипт, который каждую секунду подгружает только cost, penalty, total -->
  <script>
  function refreshMoneyFields(){
    // Запрос: bills.php?ajax=1&mode=json
    fetch("bills.php?ajax=1&mode=json", {
       method:"GET",
       credentials:"include"   // ВАЖНО: чтобы посылать cookies PHPSESSID
    })
    .then(resp=>resp.json())
    .then(data=>{
       // data = [{bill_id, cost, penalty, total_amount}, ...]
       data.forEach(item=>{
         let bId = item.bill_id;
         let costTd  = document.getElementById("cost_"+bId);
         let penTd   = document.getElementById("penalty_"+bId);
         let totTd   = document.getElementById("total_"+bId);

         if(costTd) costTd.textContent = item.cost;
         if(penTd)  penTd.textContent  = item.penalty;
         if(totTd)  totTd.textContent  = item.total_amount;
       });
    })
    .catch(err=>{
       console.error("AJAX error:", err);
    });
  }

  setInterval(refreshMoneyFields, 1000);

  window.addEventListener("DOMContentLoaded", ()=>{
    refreshMoneyFields();
  });
  </script>
  <?php endif; ?>

</head>
<body>
<header>
  <h1>Начисления</h1>
  <form method="POST">
    <button type="submit" name="logout" class="logout-btn">Выйти</button>
  </form>
  <nav>
    <a href="admin.php">Главная</a>
    <a href="tariffs.php">Тарифы</a>
    <a href="pokazaniya.php">Показания</a>
    <a href="bills.php" class="active">Начисления</a>
    <a href="payments.php">Платежи</a>
    <a href="contact.php">Контакты</a>
    <a href="news.php">Новости</a>
  </nav>
</header>

<section class="content">
<?php if (!$is_management_company): ?>
  <!-- (A) НЕ УК => Таблица неоплаченных счетов -->
  <h2>Мои неоплаченные счета</h2>

  <div class="container">
    <?php if($message): ?>
      <p class="message"><?php echo $message;?></p>
    <?php endif; ?>

    <?php
    // Сразу отрисуем всю таблицу (чтобы кнопка «Оплатить» была доступна)
    // last_update - для пересчёта пени
    $upd = $conn->prepare("
        UPDATE bills
        SET last_update=NOW()
        WHERE user_id=?
          AND payment_date IS NULL
    ");
    $upd->bind_param("i", $current_user_id);
    $upd->execute();
    $upd->close();

    // Выбираем неоплаченные
    $sql = "
      SELECT
        b.bill_id,
        t.service_type,
        b.billing_period,
        b.consumption,
        b.receiving_date,
        b.deadline_date,
        b.payment_date,
        m.meter_serial_number,
        b.cost,
        b.penalty,
        b.total_amount,
        COALESCE(rs.resource_supplier_name, mc.management_company_name) AS provider_name
      FROM bills b
      LEFT JOIN tariffs t ON b.tariff_id=t.tariff_id
      LEFT JOIN meter m ON b.meter_id=m.meter_id
      LEFT JOIN resource_supplier rs ON t.resource_supplier_id=rs.resource_supplier_id
      LEFT JOIN management_company mc ON t.management_company_id=mc.management_company_id
      WHERE b.user_id=?
        AND b.payment_date IS NULL
      ORDER BY b.billing_period DESC, b.bill_id DESC
    ";
    $st = $conn->prepare($sql);
    $st->bind_param("i", $current_user_id);
    $st->execute();
    $res=$st->get_result();
    $bills=[];
    while($row=$res->fetch_assoc()){
      $bills[]=$row;
    }
    $st->close();
    ?>

    <?php if (!empty($bills)): ?>
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
            <!-- cost, penalty, total => с ID -->
            <th>Начислено</th>
            <th>Пени</th>
            <th>Итого</th>
            <th>Оплатить</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($bills as $b):?>
          <?php 
            $bid = (int)$b['bill_id'];
            $cst = $b['cost'];
            $pen = $b['penalty'];
            $tot = $b['total_amount'];
          ?>
          <tr>
            <td><?php echo $bid;?></td>
            <td><?php echo htmlspecialchars($b['service_type']);?></td>
            <td><?php echo htmlspecialchars($b['provider_name']);?></td>
            <td><?php echo htmlspecialchars($b['billing_period']);?></td>
            <td><?php echo htmlspecialchars($b['consumption']);?></td>
            <td><?php echo htmlspecialchars($b['receiving_date']);?></td>
            <td><?php echo htmlspecialchars($b['deadline_date']);?></td>
            <td><?php echo $b['meter_serial_number'] ?: '—';?></td>
            <td id="cost_<?php echo $bid;?>"><?php echo htmlspecialchars($cst);?></td>
            <td id="penalty_<?php echo $bid;?>"><?php echo htmlspecialchars($pen);?></td>
            <td id="total_<?php echo $bid;?>"><?php echo htmlspecialchars($tot);?></td>
            <td>
              <form method="POST" style="margin:0;">
                <input type="hidden" name="bill_id" value="<?php echo $bid;?>">
                <button type="submit" name="pay_bill">Оплатить</button>
              </form>
            </td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    <?php else:?>
      <p>У вас нет неоплаченных счетов.</p>
    <?php endif;?>
  </div>

<?php else: ?>
  <!-- (B) УК => Статичная форма и таблица пользователей -->
  <h2>Начисления — УК: <?php echo htmlspecialchars($mc_name);?></h2>
  <?php
  // Форма поиска
  $search = isset($_GET['search']) ? trim($_GET['search']) : "";
  $filter_by_company = isset($_GET['filter_by_company']) ? true : false;
  ?>
  <form method="GET">
   <div class="container" style="text-align:left;">
     <label>Поиск по ФИО, логину, телефону, email, ЛС или адресу:</label><br>
     <input type="text" name="search"
            value="<?php echo htmlspecialchars($search); ?>"
            style="width:70%; padding:8px;">
     <button type="submit" style="padding:8px 16px;">Найти</button>
     <br><br>
     <label style="white-space:nowrap;">
       <input type="checkbox"
              name="filter_by_company"
              <?php echo ($filter_by_company)?'checked':''; ?>
              onchange="this.form.submit()">
       Показать только моих жителей
     </label>
   </div>
  </form>
  <?php
  // Статичная таблица пользователей
  $sql_users="
    SELECT
      u.user_id,
      u.fullname,
      u.login,
      u.phone,
      u.email,
      u.account_number,
      CONCAT(a.region,', ',a.city,', ',a.street,', дом ',a.home,
             IF(a.corpus IS NOT NULL AND a.corpus<>'', CONCAT(', корп.',a.corpus),''),
             IF(a.flat_number IS NOT NULL AND a.flat_number<>'', CONCAT(', кв.',a.flat_number),'')
      ) AS full_address
    FROM users u
    JOIN address a ON u.address_id=a.address_id
    WHERE 1=1
  ";
  $bind_types="";
  $bind_vals=[];

  if($filter_by_company){
    $sql_users.=" AND a.management_company_id=? ";
    $bind_types.="i";
    $bind_vals[]=$current_user_id;
  }
  if($search!==""){
    $sql_users.=" AND (
      u.fullname LIKE ?
      OR u.login LIKE ?
      OR u.phone LIKE ?
      OR u.email LIKE ?
      OR u.account_number LIKE ?
      OR CONCAT(a.region,' ',a.city,' ',a.street,' ',a.home,' ',a.corpus,' ',a.flat_number) LIKE ?
    )";
    $bind_types.="ssssss";
    $like="%$search%";
    for($i=0;$i<6;$i++){
      $bind_vals[]=$like;
    }
  }
  $sql_users.=" ORDER BY u.fullname ASC";

  $stm_u=$conn->prepare($sql_users);
  if($bind_types!==""){
    $stm_u->bind_param($bind_types, ...$bind_vals);
  }
  $stm_u->execute();
  $res_u=$stm_u->get_result();
  $users=[];
  while($rw=$res_u->fetch_assoc()){
    $users[]=$rw;
  }
  $stm_u->close();
  ?>
  <div class="container">
    <h3>Список пользователей</h3>
    <?php if(!empty($users)):?>
      <table>
        <thead>
         <tr>
           <th>ФИО</th>
           <th>Логин</th>
           <th>Телефон</th>
           <th>Email</th>
           <th>ЛС</th>
           <th>Адрес</th>
           <th>Действие</th>
         </tr>
        </thead>
        <tbody>
        <?php foreach($users as $u):?>
          <tr>
            <td><?php echo htmlspecialchars($u['fullname']);?></td>
            <td><?php echo htmlspecialchars($u['login']);?></td>
            <td><?php echo htmlspecialchars($u['phone']);?></td>
            <td><?php echo htmlspecialchars($u['email']);?></td>
            <td><?php echo htmlspecialchars($u['account_number']);?></td>
            <td><?php echo htmlspecialchars($u['full_address']);?></td>
            <td>
              <a href="bills_user.php?user_id=<?php echo $u['user_id'];?>">
                Показать начисления
              </a>
            </td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    <?php else:?>
      <p>Пользователи не найдены.</p>
    <?php endif;?>
  </div>
<?php endif; ?>
</section>

<footer>
  <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>

<?php
$conn->close();

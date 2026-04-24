<?php
session_start();
require_once 'auth_check.php';
// require_once 'session_timeout.php'; // при необходимости

$conn = new mysqli('localhost:3306', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Если нажата кнопка «Выйти»
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: index.php");
    exit();
}

// Проверка, УК ли
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

// Если УК => узнаём её название
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

// (4) Удаление уже оплаченого счёта (начисления), с возвратом суммы на ЛС
$message = "";
if ($is_management_company && isset($_POST['delete_paid_bill'])) {
    // bill_id
    $bill_id_del = (int)$_POST['bill_id'];

    // Проверяем, что этот счёт реально оплачен (payment_date NOT NULL)
    $stmt_bill = $conn->prepare("
        SELECT user_id, total_amount
        FROM bills
        WHERE bill_id=?
          AND payment_date IS NOT NULL
    ");
    $stmt_bill->bind_param("i", $bill_id_del);
    $stmt_bill->execute();
    $stmt_bill->bind_result($the_user_id, $the_total);
    $foundB = $stmt_bill->fetch();
    $stmt_bill->close();

    if (!$foundB) {
        $message = "Счёт #$bill_id_del не найден среди оплаченных!";
    } else {
        // Возвращаем сумму $the_total на баланс
        //  1) Увеличим account_balance у того user
        $stmt_bal = $conn->prepare("
            UPDATE users
            SET account_balance = account_balance + ?
            WHERE user_id=?
        ");
        $stmt_bal->bind_param("di", $the_total, $the_user_id);
        if (!$stmt_bal->execute()) {
            $message = "Ошибка изменения баланса: ".$stmt_bal->error;
        }
        $stmt_bal->close();

        //  2) Удаляем сам счёт (или ставим payment_date=NULL)
        //     Лучше вообще удалить запись:
        $stmt_del = $conn->prepare("DELETE FROM bills WHERE bill_id=?");
        $stmt_del->bind_param("i", $bill_id_del);
        if ($stmt_del->execute()) {
            $message = "Оплаченный счёт #$bill_id_del удалён, средства возвращены.";
        } else {
            $message = "Ошибка удаления счёта: ".$stmt_del->error;
        }
        $stmt_del->close();
    }
}

// Определяем режим просмотра (УК): view=all (все платежи), view=debtors (должники). По умолчанию view=all
$view = isset($_GET['view']) ? $_GET['view'] : 'all';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Платежи / Должники</title>
    <style>
        body {
            font-family: Arial,sans-serif;
            background: linear-gradient(135deg,#6dd5ed,#2193b0);
            color:#fff; margin:0; padding:0;
        }
        header, footer {
            text-align:center; background:rgba(0,0,0,0.6); padding:20px;
        }
        nav {
            display:flex; justify-content:center; gap:20px; margin-top:15px;
        }
        nav a {
            color:#fff; text-decoration:none; font-size:1.2em;
            padding:10px 20px; border:2px solid #fff; border-radius:25px; transition:0.3s;
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
        .container {
            background:rgba(255,255,255,0.9); color:#000;
            padding:20px; margin:20px auto; border-radius:10px; max-width:1500px; width:100%;
        }
        table {
            width:100%; border-collapse:collapse; margin-top:15px; overflow-x:auto;
        }
        th, td {
            border:1px solid #ccc; padding:8px; background:#fff; color:#000;
        }
        th { background:#2193b0; color:#fff; }
        .tabs-links { margin:10px 0; }
        .tabs-links a {
            margin:0 10px; text-decoration:none; color:#fff;
            padding:8px 16px; border:1px solid #fff; border-radius:5px;
        }
        .tabs-links a:hover {
            background:#fff; color:#2193b0;
        }
        .message { color:yellow; font-weight:bold; }
        .filter-form {
            margin-bottom:20px;
        }
        .filter-form input[type='text'] {
            width:200px; padding:6px; margin-right:10px;
        }
    </style>
</head>
<body>
<header>
    <h1>Платежи / Должники</h1>
    <form method="POST">
        <button type="submit" name="logout" class="logout-btn">Выйти</button>
    </form>
    <nav>
        <a href="admin.php">Главная</a>
        <a href="tariffs.php">Тарифы</a>
        <a href="pokazaniya.php">Показания</a>
        <a href="bills.php">Начисления</a>
        <a href="payments.php" class="active">Платежи</a>
        <a href="contact.php">Контакты</a>
        <a href="news.php">Новости</a>
    </nav>
</header>

<section class="content">

<?php if (!$is_management_company): ?>
  <!-- (3) Обычный пользователь => мои оплаченные счёта (с возможностью поиска по периоду (мм-гггг) или все) -->
  <h2>Мои оплаченные счета</h2>
  <div class="container">
    <?php if($message):?>
      <p class="message"><?php echo $message;?></p>
    <?php endif;?>

    <!-- Форма фильтра (период мм-гггг) -->
    <form method="GET" class="filter-form" style="text-align:left;">
      <label>Период (мм-гггг):</label>
      <input type="text" name="billing_period"
             placeholder="Например: 01-2025"
             value="<?php echo isset($_GET['billing_period']) ? htmlspecialchars($_GET['billing_period']) : ''; ?>">
      <button type="submit">Показать</button>
    </form>
    <?php
    // Берём period
    $user_period = isset($_GET['billing_period']) ? trim($_GET['billing_period']) : "";
    // Создадим запрос
    $sql_u = "
      SELECT
        b.bill_id,
        t.service_type,
        b.billing_period,
        b.consumption,
        b.cost,
        b.penalty,
        b.total_amount,
        b.payment_date,
        b.deadline_date,
        b.receiving_date,
        m.meter_serial_number,
        COALESCE(rs.resource_supplier_name, mc.management_company_name) AS provider_name,
        -- (1) account_number
        u.account_number,
        -- (5) days overdue = если payment_date>deadline => DATEDIFF(payment_date, deadline), иначе 0
        CASE 
          WHEN b.payment_date> b.deadline_date THEN DATEDIFF(b.payment_date, b.deadline_date)
          ELSE 0 
        END AS overdue_days
      FROM bills b
      JOIN tariffs t ON b.tariff_id=t.tariff_id
      LEFT JOIN meter m ON b.meter_id=m.meter_id
      LEFT JOIN resource_supplier rs ON t.resource_supplier_id=rs.resource_supplier_id
      LEFT JOIN management_company mc ON t.management_company_id=mc.management_company_id
      JOIN users u ON b.user_id=u.user_id
      WHERE b.user_id=?
        AND b.payment_date IS NOT NULL
    ";
    $bt = "i";
    $vals = [$_SESSION['user_id']];

    if ($user_period!="") {
        $sql_u.=" AND b.billing_period=? ";
        $bt.="s";
        $vals[]=$user_period;
    }
    $sql_u.=" ORDER BY b.payment_date DESC";

    $stmt_u = $conn->prepare($sql_u);
    $stmt_u->bind_param($bt, ...$vals);
    $stmt_u->execute();
    $r_u = $stmt_u->get_result();
    $paid_bills = [];
    while($rw=$r_u->fetch_assoc()){
      $paid_bills[]=$rw;
    }
    $stmt_u->close();

    if($paid_bills): ?>
      <table>
        <thead>
          <tr>
            <th>ID счёта</th>
            <th>ЛС</th> <!-- account_number -->
            <th>Услуга</th>
            <th>Поставщик/УК</th>
            <th>Период</th>
            <th>Потребление</th>
            <th>Счётчик</th>
            <th>Начислено</th>
            <th>Пени</th>
            <th>Итого</th>
            <th>Дата выставления</th>
            <th>Дата оплаты</th>
            <th>Крайний срок</th>
            <th>Просрочено (дн.)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($paid_bills as $pb): ?>
            <tr>
              <td><?php echo $pb['bill_id'];?></td>
              <td><?php echo htmlspecialchars($pb['account_number']);?></td>
              <td><?php echo htmlspecialchars($pb['service_type']);?></td>
              <td><?php echo htmlspecialchars($pb['provider_name']);?></td>
              <td><?php echo htmlspecialchars($pb['billing_period']);?></td>
              <td><?php echo htmlspecialchars($pb['consumption']);?></td>
              <td><?php echo ($pb['meter_serial_number'] ?: '—');?></td>
              <td><?php echo htmlspecialchars($pb['cost']);?></td>
              <td><?php echo htmlspecialchars($pb['penalty']);?></td>
              <td><?php echo htmlspecialchars($pb['total_amount']);?></td>
              <td><?php echo htmlspecialchars($pb['receiving_date']);?></td>
              <td><?php echo htmlspecialchars($pb['payment_date']);?></td>
              <td><?php echo htmlspecialchars($pb['deadline_date']);?></td>
              <td><?php echo (int)$pb['overdue_days'];?></td>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    <?php else: ?>
      <p>У вас нет оплаченных счетов<?php echo ($user_period)?" за период $user_period":"";?>.</p>
    <?php endif; ?>

  </div>

<?php else: ?>
  <!-- УК -->
  <h2>УК: <?php echo htmlspecialchars($mc_name); ?> — Платежи и должники</h2>
  <div class="tabs-links">
    <a href="?view=all" style="<?php echo ($view=='all'?'background:#fff;color:#2193b0;':''); ?>">Все платежи</a>
    <a href="?view=debtors" style="<?php echo ($view=='debtors'?'background:#fff;color:#2193b0;':''); ?>">Должники</a>
  </div>

  <?php if($message):?>
    <p class="message"><?php echo $message;?></p>
  <?php endif;?>

  <?php if ($view=='all'): ?>
    <!-- (2) Все платежи => форма поиска по ЛС, ФИО, логину и т.д. + таблица оплаченных счетов
         + возможность (4) удаления оплаченных, возвращая сумму на ЛС -->
    <div class="container" style="text-align:left;">
      <h3>Все оплаченные счета</h3>

      <!-- Форма поиска (ФИО, login, ЛС, ... + billing_period, ???) -->
      <?php
      $search = isset($_GET['search']) ? trim($_GET['search']) : "";
      // search будет по ФИО, логину, ЛС, адресу, ...
      $bill_period = isset($_GET['bill_period']) ? trim($_GET['bill_period']) : "";
      ?>
      <form method="GET" class="filter-form">
        <input type="hidden" name="view" value="all">
        <label>Поиск (ФИО, логин, ЛС, адрес):</label>
        <input type="text" name="search" value="<?php echo htmlspecialchars($search);?>">
        <br><br>
        <label>Период (мм-гггг):</label>
        <input type="text" name="bill_period" placeholder="01-2025"
               value="<?php echo htmlspecialchars($bill_period);?>">
        <br><br>
        <button type="submit">Найти</button>
      </form>

      <?php
      // Собираем оплаченные счета
      $sql_all = "
        SELECT
          b.bill_id,
          b.user_id,
          u.account_number,
          u.fullname,
          u.login,
          CONCAT(a.region, ', ', a.city, ', ', a.street, ', д. ', a.home,
                 IF(a.corpus IS NOT NULL AND a.corpus<>'', CONCAT(', корп. ', a.corpus), ''),
                 IF(a.flat_number IS NOT NULL AND a.flat_number<>'', CONCAT(', кв. ', a.flat_number), '')
          ) AS full_address,
          t.service_type,
          b.billing_period,
          b.consumption,
          b.cost,
          b.penalty,
          b.total_amount,
          b.payment_date,
          b.deadline_date,
          b.receiving_date,
          -- days overdue
          CASE 
            WHEN b.payment_date> b.deadline_date THEN DATEDIFF(b.payment_date,b.deadline_date)
            ELSE 0
          END AS overdue_days
        FROM bills b
        JOIN users u ON b.user_id=u.user_id
        JOIN address a ON u.address_id=a.address_id
        JOIN tariffs t ON b.tariff_id=t.tariff_id
        WHERE b.payment_date IS NOT NULL
      ";
      $bt = "";
      $vals = [];

      if($search!==""){
        $sql_all.=" AND (
          u.fullname LIKE ?
          OR u.login LIKE ?
          OR u.account_number LIKE ?
          OR CONCAT(a.region,a.city,a.street,a.home,a.corpus,a.flat_number) LIKE ?
        )";
        $bt.="ssss";
        $like_s="%$search%";
        for($i=0;$i<4;$i++){ $vals[]=$like_s;}
      }
      if($bill_period!==""){
        $sql_all.=" AND b.billing_period=? ";
        $bt.="s";
        $vals[]=$bill_period;
      }
      $sql_all.=" ORDER BY b.payment_date DESC";

      $stmt_all = $conn->prepare($sql_all);
      if($bt!==""){
        $stmt_all->bind_param($bt, ...$vals);
      }
      $stmt_all->execute();
      $r_all=$stmt_all->get_result();
      $paidList=[];
      while($rr=$r_all->fetch_assoc()){
        $paidList[]=$rr;
      }
      $stmt_all->close();

      if($paidList): ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>ЛС</th>
              <th>ФИО</th>
              <th>Логин</th>
              <th>Адрес</th>
              <th>Услуга</th>
              <th>Период</th>
              <th>Потребление</th>
              <th>Начислено</th>
              <th>Пени</th>
              <th>Итого</th>
              <th>Дата выставления</th>
              <th>Дата оплаты</th>
              <th>Крайний срок</th>
              <th>Просрочено (дн.)</th>
              <th>Удалить</th> <!-- (4) вернуть сумму -->
            </tr>
          </thead>
          <tbody>
            <?php foreach($paidList as $pl): ?>
              <tr>
                <td><?php echo $pl['bill_id'];?></td>
                <td><?php echo htmlspecialchars($pl['account_number']);?></td>
                <td><?php echo htmlspecialchars($pl['fullname']);?></td>
                <td><?php echo htmlspecialchars($pl['login']);?></td>
                <td><?php echo htmlspecialchars($pl['full_address']);?></td>
                <td><?php echo htmlspecialchars($pl['service_type']);?></td>
                <td><?php echo htmlspecialchars($pl['billing_period']);?></td>
                <td><?php echo htmlspecialchars($pl['consumption']);?></td>
                <td><?php echo htmlspecialchars($pl['cost']);?></td>
                <td><?php echo htmlspecialchars($pl['penalty']);?></td>
                <td><?php echo htmlspecialchars($pl['total_amount']);?></td>
                <td><?php echo htmlspecialchars($pl['receiving_date']);?></td>
                <td><?php echo htmlspecialchars($pl['payment_date']);?></td>
                <td><?php echo htmlspecialchars($pl['deadline_date']);?></td>
                <td><?php echo (int)$pl['overdue_days'];?></td>
                <td>
                  <!-- Кнопка удалить (вернуть сумму на ЛС) -->
                  <form method="POST" 
                        onsubmit="return confirm('Удалить оплаченный счёт #<?php echo $pl['bill_id'];?>? Сумма будет возвращена на ЛС.')">
                    <input type="hidden" name="bill_id" value="<?php echo $pl['bill_id'];?>">
                    <button type="submit" name="delete_paid_bill" style="background:red;color:#fff;">
                      Удалить
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      <?php else: ?>
        <p>Оплаченные счета не найдены.</p>
      <?php endif;?>
    </div>
  <?php else: ?>
    <!-- (view=debtors) => Должники -->
    <div class="container" style="text-align:left;">
      <h3>Список должников (просроченные)</h3>
      <?php
      // форма поиска
      $search_d = isset($_GET['search_d']) ? trim($_GET['search_d']) : "";
      $filter_by_company_d = isset($_GET['filter_by_company_d']) ? true : false;
      $sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : "";
      ?>
      <form method="GET">
        <input type="hidden" name="view" value="debtors">
        <label>Поиск по ФИО, логину, адресу, услуге и т.п.:</label><br>
        <input type="text" name="search_d" value="<?php echo htmlspecialchars($search_d);?>" style="width:70%;padding:8px;">
        <button type="submit" style="padding:8px 16px;">Найти</button>
        <br><br>
        <label>
          <input type="checkbox" name="filter_by_company_d"
                 <?php echo ($filter_by_company_d?'checked':'');?> 
                 onchange="this.form.submit()">
          Только мои жители
        </label>
        <br><br>
        <label>Сортировать по:</label>
        <select name="sort_by" onchange="this.form.submit()">
          <option value="">-- Без сортировки --</option>
          <option value="overdue_days" <?php if($sort_by=='overdue_days') echo 'selected';?>>Дням просрочки (убывание)</option>
          <option value="penalty" <?php if($sort_by=='penalty') echo 'selected';?>>Размеру пени (убывание)</option>
        </select>
      </form>
      <br>
      <?php
      $sql_debt = "
        SELECT
          b.bill_id,
          b.user_id,
          u.fullname,
          u.login,
          u.account_number, 
          CONCAT(a.region, ', ', a.city, ', ', a.street, ', д. ', a.home,
                 IF(a.corpus IS NOT NULL AND a.corpus<>'', CONCAT(', корп. ', a.corpus), ''),
                 IF(a.flat_number IS NOT NULL AND a.flat_number<>'', CONCAT(', кв. ', a.flat_number), '')
          ) AS full_address,
          t.service_type,
          b.billing_period,
          b.cost,
          b.penalty,
          b.deadline_date,
          b.receiving_date,
          DATEDIFF(NOW(), b.deadline_date) AS overdue_days
        FROM bills b
        JOIN users u ON b.user_id=u.user_id
        JOIN address a ON u.address_id=a.address_id
        JOIN tariffs t ON b.tariff_id=t.tariff_id
        WHERE b.payment_date IS NULL
          AND b.deadline_date < NOW()
      ";
      $bind_d = "";
      $vals_d = [];

      if($filter_by_company_d) {
        $sql_debt.=" AND a.management_company_id=? ";
        $bind_d.="i";
        $vals_d[]=$current_user_id;
      }
      if($search_d!==""){
        $sql_debt.=" AND (
           u.fullname LIKE ?
           OR u.login LIKE ?
           OR u.account_number LIKE ?
           OR CONCAT(a.region,a.city,a.street,a.home,a.corpus,a.flat_number) LIKE ?
           OR t.service_type LIKE ?
        )";
        $bind_d.="sssss";
        $like_d="%$search_d%";
        for($i=0;$i<5;$i++){
          $vals_d[]=$like_d;
        }
      }
      if($sort_by=='overdue_days') {
        $sql_debt.=" ORDER BY overdue_days DESC, b.bill_id DESC";
      } elseif($sort_by=='penalty') {
        $sql_debt.=" ORDER BY b.penalty DESC, b.bill_id DESC";
      } else {
        $sql_debt.=" ORDER BY b.deadline_date ASC, b.bill_id DESC";
      }

      $stmt_d = $conn->prepare($sql_debt);
      if($bind_d!==""){
        $stmt_d->bind_param($bind_d, ...$vals_d);
      }
      $stmt_d->execute();
      $res_d=$stmt_d->get_result();
      $debtors=[];
      while($rd=$res_d->fetch_assoc()){
        $debtors[]=$rd;
      }
      $stmt_d->close();

      if($debtors):?>
        <table>
          <thead>
            <tr>
              <th>ID счёта</th>
              <th>ФИО</th>
              <th>Логин</th>
              <th>ЛС</th> <!-- account_number -->
              <th>Адрес</th>
              <th>Услуга</th>
              <th>Период</th>
              <th>Начислено</th>
              <th>Пени</th>
              <th>Дата выставления</th>
              <th>Срок оплаты</th>
              <th>Дней просрочки</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($debtors as $db):?>
              <tr>
                <td><?php echo $db['bill_id'];?></td>
                <td><?php echo htmlspecialchars($db['fullname']);?></td>
                <td><?php echo htmlspecialchars($db['login']);?></td>
                <td><?php echo htmlspecialchars($db['account_number']);?></td>
                <td><?php echo htmlspecialchars($db['full_address']);?></td>
                <td><?php echo htmlspecialchars($db['service_type']);?></td>
                <td><?php echo htmlspecialchars($db['billing_period']);?></td>
                <td><?php echo htmlspecialchars($db['cost']);?></td>
                <td><?php echo htmlspecialchars($db['penalty']);?></td>
                <td><?php echo htmlspecialchars($db['receiving_date']);?></td>
                <td><?php echo htmlspecialchars($db['deadline_date']);?></td>
                <td><?php echo (int)$db['overdue_days'];?></td>
              </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      <?php else: ?>
        <p>Нет должников или ничего не найдено.</p>
      <?php endif;?>
    </div>
  <?php endif; ?>
<?php endif; ?>

</section>

<footer>
    <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>

<?php
$conn->close();

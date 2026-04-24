<?php
session_start();
require_once 'auth_check.php';
require_once 'session_timeout.php';

$conn = new mysqli('localhost:3306', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Если нажата кнопка "Выйти"
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: index.php");
    exit();
}

// user_id, чьи оплаченные квитанции хотим смотреть
if (!isset($_GET['user_id'])) {
    die("Не указан user_id пользователя.");
}
$view_user_id = (int)$_GET['user_id'];

// Проверка, УК ли текущий (или сам пользователь)
$current_user_id = $_SESSION['user_id'];
$stmt_check_mc = $conn->prepare("
    SELECT management_company_id
    FROM management_company
    WHERE management_company_id = ?
");
$stmt_check_mc->bind_param("i", $current_user_id);
$stmt_check_mc->execute();
$stmt_check_mc->store_result();
$is_management_company = ($stmt_check_mc->num_rows > 0);
$stmt_check_mc->close();

// Если не УК и не сам пользователь — отказ
if (!$is_management_company && $current_user_id != $view_user_id) {
    die("Недостаточно прав для просмотра чужих платежей.");
}

// Если УК, получим её название
$mc_name = "";
if ($is_management_company) {
    $stmt_mc = $conn->prepare("
        SELECT management_company_name
        FROM management_company
        WHERE management_company_id = ?
    ");
    $stmt_mc->bind_param("i", $current_user_id);
    $stmt_mc->execute();
    $stmt_mc->bind_result($mc_name);
    $stmt_mc->fetch();
    $stmt_mc->close();
}

// Узнаем ФИО пользователя (того, чьи платежи смотрим)
$stmt_user = $conn->prepare("
    SELECT fullname
    FROM users
    WHERE user_id = ?
");
$stmt_user->bind_param("i", $view_user_id);
$stmt_user->execute();
$stmt_user->bind_result($selected_user_name);
$stmt_user->fetch();
$stmt_user->close();

// Параметр периода
$selected_period = isset($_GET['billing_period']) ? trim($_GET['billing_period']) : "";

// Получаем оплаченные счета этого пользователя за указанный период
$payments = [];
$sql = "
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
        m.meter_serial_number
    FROM bills b
    JOIN tariffs t ON b.tariff_id = t.tariff_id
    LEFT JOIN meter m ON b.meter_id = m.meter_id
    WHERE b.user_id = ?
      AND b.payment_date IS NOT NULL
";
$bind_types = "i";
$bind_vals = [$view_user_id];

// Если выбран период
if ($selected_period !== "") {
    $sql .= " AND b.billing_period = ? ";
    $bind_types .= "s";
    $bind_vals[] = $selected_period;
}
$sql .= " ORDER BY b.payment_date DESC";

$stmt_p = $conn->prepare($sql);
$stmt_p->bind_param($bind_types, ...$bind_vals);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
while ($row = $res_p->fetch_assoc()) {
    $payments[] = $row;
}
$stmt_p->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Оплаченные квитанции пользователя</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #6dd5ed, #2193b0);
            color: #fff; margin:0; padding:0;
        }
        header, footer {
            text-align: center; background: rgba(0,0,0,0.6); padding:20px;
        }
        nav {
            display: flex; justify-content: center; gap: 20px; margin-top:15px;
        }
        nav a {
            color: #fff; text-decoration: none; font-size:1.2em;
            padding: 10px 20px; border: 2px solid #fff; border-radius:25px;
            transition: 0.3s;
        }
        nav a:hover, nav a.active {
            background: #fff; color: #2193b0;
        }
        .logout-btn {
            position: absolute; top:20px; right:20px;
            background: red; color:#fff; border:none; padding:10px 20px;
            border-radius:5px; cursor:pointer;
        }
        .logout-btn:hover { background: darkred; }
        .content { padding:20px; text-align:center; }
        .container {
            background: rgba(255,255,255,0.9); color:#000;
            padding:20px; margin:20px auto; border-radius:10px;
            max-width:1500px; width:100%;
        }
        table {
            width:100%; border-collapse: collapse; margin-top:15px; overflow-x:auto;
        }
        th, td {
            border:1px solid #ccc; padding:8px;
            background:#fff; color:#000;
        }
        th { background:#2193b0; color:#fff; }
        .message { color:yellow; font-weight: bold; }
    </style>
</head>
<body>
<header>
    <h1>Оплаченные квитанции пользователя</h1>
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
        <h2>УК: <?php echo htmlspecialchars($mc_name); ?> — Оплаченные квитанции пользователя: <?php echo htmlspecialchars($selected_user_name); ?></h2>
    <?php else: ?>
        <h2>Мои оплаченные квитанции — <?php echo htmlspecialchars($selected_user_name); ?></h2>
    <?php endif; ?>

    <div class="container">
        <!-- Форма выбора периода (мм-гггг) -->
        <form method="GET" style="margin-bottom:20px;">
            <input type="hidden" name="user_id" value="<?php echo $view_user_id; ?>">
            <label>Период (мм-гггг):</label>
            <input type="text" name="billing_period"
                   placeholder="Например: 01-2025"
                   value="<?php echo htmlspecialchars($selected_period); ?>"
                   style="width:120px;">
            <button type="submit">Показать</button>
        </form>

        <?php if (!empty($payments)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID счёта</th>
                        <th>Услуга</th>
                        <th>Период</th>
                        <th>Потребление</th>
                        <th>Счётчик</th>
                        <th>Начислено</th>
                        <th>Пеня</th>
                        <th>Итого</th>
                        <th>Дата оплаты</th>
                        <th>Крайний срок</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?php echo $p['bill_id']; ?></td>
                            <td><?php echo htmlspecialchars($p['service_type']); ?></td>
                            <td><?php echo htmlspecialchars($p['billing_period']); ?></td>
                            <td><?php echo htmlspecialchars($p['consumption']); ?></td>
                            <td><?php echo $p['meter_serial_number'] ? htmlspecialchars($p['meter_serial_number']) : '—'; ?></td>
                            <td><?php echo htmlspecialchars($p['cost']); ?></td>
                            <td><?php echo htmlspecialchars($p['penalty']); ?></td>
                            <td><?php echo htmlspecialchars($p['total_amount']); ?></td>
                            <td><?php echo htmlspecialchars($p['payment_date']); ?></td>
                            <td><?php echo htmlspecialchars($p['deadline_date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Оплаченных квитанций не найдено за указанный период или вообще нет.</p>
        <?php endif; ?>
    </div>
</section>

<footer>
    <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>

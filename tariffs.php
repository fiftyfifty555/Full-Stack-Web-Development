<?php
session_start();
require_once 'session_timeout.php';

// Определяем, авторизован ли пользователь
$is_authorized = isset($_SESSION['user_id']);
$role = $is_authorized ? $_SESSION['role'] : null;

// Определяем ссылку «Главная»
if ($is_authorized) {
    $home_link = ($role === 'admin') ? 'admin.php' : 'auth.php';
} else {
    $home_link = 'index.php'; // Для неавторизованных
}

// Обработка выхода
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: index.php");
    exit();
}

// Подключаемся к БД
$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения к БД: " . $conn->connect_error);
}

/*
Логика отбора тарифов:
1) Неавторизованный:
   - Показываем все тарифы, где service_type != 'Содержание жилого помещения'.
2) Пользователь (role='user'):
   - Показываем все тарифы ресурсоснабжающих организаций,
   - Плюс ровно один тариф 'Содержание жилого помещения' от УК, к которой он относится.
3) Админ (role='admin'):
   - Показываем все ресурсные тарифы,
   - Плюс один 'Содержание жилого помещения' для своей УК (id УК = $_SESSION['user_id']).
*/

// Базовый SELECT для вывода тарифов (добавлено поле t.normative)
$sql = "
SELECT 
    t.service_type,
    t.price,
    t.measurement_unit,
    t.normative,  -- <-- новое поле для вывода норматива
    -- Поставщик/УК:
    COALESCE(rs.resource_supplier_name, mc.management_company_name) AS supplier_name, 
    -- Адрес поставщика/УК:
    COALESCE(rs.resource_supplier_address, mc.management_company_address) AS address,
    -- Телефон поставщика/УК:
    COALESCE(rs.resource_supplier_phone, mc.management_company_phone) AS phone,
    -- Email:
    COALESCE(rs.resource_supplier_email, mc.management_company_email) AS email,
    -- Сайт (для ресурсника: website, для УК - у вас пока нет отдельного поля, но оставим как в примере):
    rs.resource_supplier_website AS supplier_website,
    mc.management_company_workhours AS mc_workhours
FROM tariffs t
LEFT JOIN resource_supplier rs ON t.resource_supplier_id = rs.resource_supplier_id
LEFT JOIN management_company mc ON t.management_company_id = mc.management_company_id
";

if (!$is_authorized) {
    // 1) Неавторизованный -> исключаем 'Содержание жилого помещения'
    $sql .= " 
       WHERE t.service_type != 'Содержание жилого помещения'
    ";
} else {
    // 2) или 3) Авторизованный
    if ($role === 'user') {
        // Находим УК, к которой принадлежит адрес пользователя
        $sql_user_mc = "
            SELECT a.management_company_id
            FROM users u
            JOIN address a ON u.address_id = a.address_id
            WHERE u.user_id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql_user_mc);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $stmt->bind_result($user_mc_id);
        $stmt->fetch();
        $stmt->close();

        if (!$user_mc_id) {
            // Если почему-то не найдено
            $user_mc_id = 0;
        }

        // Показать все тарифы (service_type != 'Содержание...') ИЛИ (содержание... AND mc = user_mc_id)
        $sql .= "
           WHERE (
             t.service_type != 'Содержание жилого помещения'
           )
           OR (
             t.service_type = 'Содержание жилого помещения'
             AND t.management_company_id = $user_mc_id
           )
        ";
    }
    elseif ($role === 'admin') {
        // Админ. Предполагаем user_id админа = management_company_id
        $mc_id = $_SESSION['user_id'];

        $sql .= "
           WHERE (
             t.service_type != 'Содержание жилого помещения'
           )
           OR (
             t.service_type = 'Содержание жилого помещения'
             AND t.management_company_id = $mc_id
           )
        ";
    }
}

// Выполняем запрос
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тарифы ЖКХ</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0; padding: 0;
            background: linear-gradient(135deg, #6dd5ed, #2193b0);
            color: #fff; 
            display: flex; 
            flex-direction: column; 
            min-height: 100vh;
        }
        header {
            background: rgba(0,0,0,0.6); 
            padding: 20px 0; 
            text-align: center;
            position: fixed; 
            top: 0; left: 0; 
            width: 100%; 
            z-index: 1000;
        }
        header h1 { 
            margin: 0; 
            font-size: 2.5em; 
            color: #fff; 
        }
        nav {
            display: flex; 
            justify-content: center; 
            gap: 20px; 
            margin-top: 15px;
        }
        nav a {
            color: #fff; 
            text-decoration: none; 
            font-size: 1.2em; 
            padding: 10px 20px;
            border: 2px solid #fff; 
            border-radius: 25px; 
            transition: 0.3s;
        }
        nav a:hover, nav a.active { 
            background: #fff; 
            color: #2193b0; 
        }
        .logout-btn {
            position: absolute; 
            top: 20px; 
            right: 20px; 
            background: red; 
            color: #fff;
            border: none; 
            padding: 10px 20px; 
            border-radius: 5px; 
            cursor: pointer; 
            font-size: 1em;
        }
        .logout-btn:hover { 
            background: darkred; 
        }
        .content {
            padding: 160px 20px 50px; 
            text-align: center; 
            flex: 1;
        }
        table {
            width: 80%; 
            margin: 0 auto; 
            border-collapse: collapse; 
            background: #fff; 
            color: #000;
        }
        table th, table td {
            padding: 15px; 
            border: 1px solid #ddd; 
            text-align: center;
        }
        table th {
            background: #2193b0; 
            color: #fff;
        }
        footer {
            text-align: center; 
            padding: 20px; 
            background: rgba(0,0,0,0.8);
            color: #fff; 
            margin-top: auto;
        }
    </style>
</head>
<body>
<header>
    <h1>Тарифы ЖКХ</h1>
    <?php if ($is_authorized): ?>
        <form method="POST">
            <button type="submit" name="logout" class="logout-btn">Выйти</button>
        </form>
    <?php endif; ?>
    <nav>
      <a href="<?php echo $home_link; ?>"
         class="<?php echo (basename($_SERVER['PHP_SELF'])=='index.php' 
                            || basename($_SERVER['PHP_SELF'])=='auth.php' 
                            || basename($_SERVER['PHP_SELF'])=='admin.php') ? 'active' : ''; ?>">
         Главная
      </a>
      <a href="tariffs.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='tariffs.php' ? 'active':''; ?>">Тарифы</a>
      <a href="pokazaniya.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='pokazaniya.php' ? 'active':''; ?>">Показания</a>
      <a href="bills.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='bills.php' ? 'active':''; ?>">Начисления</a>
      <a href="payments.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='payments.php' ? 'active':''; ?>">Платежи</a>
      <a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='contact.php' ? 'active':''; ?>">Контакты</a>
      <a href="news.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='news.php' ? 'active':''; ?>">Новости</a>
    </nav>
</header>

<section class="content">
    <h2>Доступные тарифы</h2>
    <table>
        <thead>
            <tr>
                <th>Название услуги</th>
                <th>Цена</th>
                <th>Ед.изм.</th>
                <th>Норматив</th> <!-- Новый столбец -->
                <th>Поставщик / УК</th>
                <th>Адрес</th>
                <th>Телефон</th>
                <th>Email</th>
                <th>Сайт</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $service_type   = $row['service_type'];
                    $price          = $row['price'];
                    $unit           = $row['measurement_unit'];
                    $normative      = $row['normative']; // выведем, если есть
                    $supplier_name  = $row['supplier_name'];
                    $address        = $row['address'];
                    $phone          = $row['phone'];
                    $email          = $row['email'];
                    
                    // Для сайта / работы:
                    $website_supplier = $row['supplier_website']; // resource_supplier_website
                    $website_mc       = $row['mc_workhours'];     // management_company_workhours (не сайт, но поля нет)
                    
                    // Определим, что выводить в "Сайт"
                    $website_link = "";
                    if (!empty($website_supplier)) {
                        // Делать гиперссылку
                        $website_link = "<a href='".htmlspecialchars($website_supplier)."' target='_blank'>Перейти</a>";
                    } else {
                        $website_link = "—";
                    }

                    // Норматив выведем как есть, если NULL => ставим "—"
                    $normative_str = ($normative !== null) ? $normative : "—";

                    echo "<tr>";
                    echo "<td>".htmlspecialchars($service_type)."</td>";
                    echo "<td>".htmlspecialchars($price)." руб.</td>";
                    echo "<td>".htmlspecialchars($unit)."</td>";
                    echo "<td>".htmlspecialchars($normative_str)."</td>";
                    echo "<td>".htmlspecialchars($supplier_name)."</td>";
                    echo "<td>".htmlspecialchars($address)."</td>";
                    echo "<td>".htmlspecialchars($phone)."</td>";
                    echo "<td>".htmlspecialchars($email)."</td>";
                    echo "<td>".$website_link."</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='9'>Тарифы не найдены</td></tr>";
            }
            $conn->close();
            ?>
        </tbody>
    </table>
</section>

<footer>
    <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>

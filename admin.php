<?php
session_start();
require_once 'auth_check.php';
// require_once 'session_timeout.php'; // при необходимости

// Подключение к БД
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

// Получаем ID и данные текущей УК
$management_company_id = $_SESSION['user_id']; 
$stmt_mc = $conn->prepare("
    SELECT 
      management_company_name, 
      management_company_phone, 
      management_company_email, 
      management_company_address, 
      management_company_workhours, 
      management_company_INN, 
      management_company_KPP, 
      management_company_payacc, 
      management_company_BIK, 
      management_company_coracc
    FROM management_company
    WHERE management_company_id=?
");
$stmt_mc->bind_param("i", $management_company_id);
$stmt_mc->execute();
$stmt_mc->bind_result(
    $mc_name, $mc_phone, $mc_email, 
    $mc_address, $mc_workhours, 
    $mc_INN, $mc_KPP, $mc_payacc, 
    $mc_BIK, $mc_coracc
);
$stmt_mc->fetch();
$stmt_mc->close();

$message = "";

/** Обработка формы создания нового пользователя **/
if (isset($_POST['create_user'])) {
    // Собираем данные из формы (SERVER-SIDE проверка!)
    $fullname       = trim($_POST['fullname'] ?? '');
    $login          = trim($_POST['login'] ?? '');
    $password       = trim($_POST['password'] ?? '');
    $date_of_birth  = trim($_POST['date_of_birth'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $passport_series= trim($_POST['passport_series'] ?? '');
    $passport_number= trim($_POST['passport_number'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $INN            = trim($_POST['INN'] ?? '');
    $region         = trim($_POST['region'] ?? '');
    $city           = trim($_POST['city'] ?? '');
    $street         = trim($_POST['street'] ?? '');
    $home           = trim($_POST['home'] ?? '');
    $corpus         = trim($_POST['corpus'] ?? '');
    $post_index     = trim($_POST['post_index'] ?? '');
    $flat_number    = trim($_POST['flat_number'] ?? '');
    $square         = trim($_POST['square'] ?? '');
    $residents_number = trim($_POST['residents_number'] ?? '');

    // management_company_id не с формы, а берём из сессии
    $mc_id_forNewUser = $management_company_id;

    try {
        // --- Блок «дублирующей» проверки (на сервере). ---
        // 1) ФИО: только буквы/пробелы/дефис, макс 150
        if (!preg_match('/^[A-Za-zА-Яа-яЁё\s\-]{1,150}$/u', $fullname)) {
            throw new Exception("Ошибка: ФИО может содержать только буквы, пробелы, дефис. Максимум 150 символов.");
        }
        // 2) Логин: до 20 символов (не пуст)
        if (mb_strlen($login) < 1 || mb_strlen($login) > 20) {
            throw new Exception("Ошибка: Логин не должен превышать 20 символов.");
        }
        // 3) Пароль: до 15 символов
        if (mb_strlen($password) < 1 || mb_strlen($password) > 15) {
            throw new Exception("Ошибка: Пароль не должен превышать 15 символов.");
        }
        // 4) Телефон: до 11 цифр
        if (!preg_match('/^\d{1,11}$/', $phone)) {
            throw new Exception("Ошибка: Телефон должен содержать до 11 цифр (только цифры).");
        }
        // 5) Email: до 30 символов + фильтр
        if (mb_strlen($email) > 30) {
            throw new Exception("Ошибка: Email не должен превышать 30 символов.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Ошибка: Неверный формат Email.");
        }
        // 6) Паспорт серия: ровно 4 цифры
        if (!preg_match('/^\d{4}$/', $passport_series)) {
            throw new Exception("Ошибка: Серия паспорта должна содержать ровно 4 цифры.");
        }
        // 7) Паспорт номер: ровно 6 цифр
        if (!preg_match('/^\d{6}$/', $passport_number)) {
            throw new Exception("Ошибка: Номер паспорта должен содержать ровно 6 цифр.");
        }
        // 8) Account_number: до 20 символов
        if (mb_strlen($account_number) < 1 || mb_strlen($account_number) > 20) {
            throw new Exception("Ошибка: Номер лицевого счета не должен превышать 20 символов.");
        }
        // 9) INN: ровно 12 цифр
        if (!preg_match('/^\d{12}$/', $INN)) {
            throw new Exception("Ошибка: ИНН должен содержать ровно 12 цифр.");
        }
        // 10) region: до 35
        if (mb_strlen($region)<1 || mb_strlen($region)>35) {
            throw new Exception("Ошибка: Поле 'Регион' не должно превышать 35 символов.");
        }
        // 11) city: до 20
        if (mb_strlen($city)<1 || mb_strlen($city)>20) {
            throw new Exception("Ошибка: Поле 'Город' не должно превышать 20 символов.");
        }
        // 12) street: до 50
        if (mb_strlen($street)<1 || mb_strlen($street)>50) {
            throw new Exception("Ошибка: Поле 'Улица' не должно превышать 50 символов.");
        }
        // 13) home: до 4
        if (mb_strlen($home)<1 || mb_strlen($home)>4) {
            throw new Exception("Ошибка: 'Дом' не должен превышать 4 символов.");
        }
        // 14) corpus: до 2
        if ($corpus !== '' && mb_strlen($corpus)>2) {
            throw new Exception("Ошибка: 'Корпус' не должен превышать 2 символов.");
        }
        // 15) post_index: ровно 6 цифр
        if (!preg_match('/^\d{6}$/', $post_index)) {
            throw new Exception("Ошибка: Почтовый индекс должен содержать ровно 6 цифр.");
        }
        // 16) flat_number: до 4
        if ($flat_number!=='' && mb_strlen($flat_number)>4) {
            throw new Exception("Ошибка: 'Квартира' не должна превышать 4 символов.");
        }
        // 17) square => numeric, >0
        if (!is_numeric($square) || floatval($square)<=0) {
            throw new Exception("Ошибка: Площадь должна быть положительным числом.");
        }
        // 18) residents_number => numeric, >=1
        if (!ctype_digit($residents_number) || intval($residents_number)<1) {
            throw new Exception("Ошибка: Количество жильцов должно быть целым положительным числом.");
        }
        // 19) date_of_birth => проверим формат
        if (strtotime($date_of_birth)===false) {
            throw new Exception("Ошибка: Некорректная дата рождения.");
        }

        // 1) Проверка, нет ли такого логина
        $stmt_check_login = $conn->prepare("
            SELECT user_id FROM users WHERE login=?
        ");
        $stmt_check_login->bind_param("s", $login);
        $stmt_check_login->execute();
        $stmt_check_login->store_result();
        if ($stmt_check_login->num_rows>0) {
            throw new Exception("Ошибка: Логин уже занят.");
        }
        $stmt_check_login->close();

        // 2) Проверяем наличие адреса
        $stmt_check_address = $conn->prepare("
            SELECT address_id
            FROM address
            WHERE region=? AND city=? AND street=? 
              AND home=? AND corpus=? AND post_index=?
              AND flat_number=?
        ");
        $stmt_check_address->bind_param("sssssss",
            $region, $city, $street,
            $home, $corpus, $post_index,
            $flat_number
        );
        $stmt_check_address->execute();
        $stmt_check_address->store_result();

        if ($stmt_check_address->num_rows>0) {
            throw new Exception("Ошибка: Такой адрес уже существует — лицевой счет уже есть.");
        }
        $stmt_check_address->close();

        // 3) Вставляем в address
        $stmt_address = $conn->prepare("
            INSERT INTO address(
               region, city, street, home, corpus, post_index,
               flat_number, square, residents_number, management_company_id
            ) 
            VALUES(?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt_address->bind_param("sssssssiii",
            $region, $city, $street, $home, $corpus, $post_index,
            $flat_number, $square, $residents_number, $mc_id_forNewUser
        );
        if (!$stmt_address->execute()) {
            throw new Exception("Ошибка вставки address: ".$stmt_address->error);
        }
        $address_id = $stmt_address->insert_id;
        $stmt_address->close();

        // 4) Вставляем в users
        $stmt_user = $conn->prepare("
            INSERT INTO users(
               fullname, login, password, date_of_birth, phone,
               email, passport_series, passport_number, account_number,
               account_balance, INN, address_id
            ) 
            VALUES(?,?,?,?,?,?,?,?,?,0,?,?)
        ");
        $stmt_user->bind_param("ssssssssssi",
            $fullname, $login, $password, $date_of_birth, $phone,
            $email, $passport_series, $passport_number, $account_number,
            $INN, $address_id
        );
        if (!$stmt_user->execute()) {
            throw new Exception("Ошибка создания пользователя: ".$stmt_user->error);
        }
        $stmt_user->close();

        $message = "Пользователь успешно создан!";
    } 
    catch(Exception $ex) {
        $message = $ex->getMessage();
    } 
    finally {
        // $conn->query("UNLOCK TABLES");
    }
}

// --- Фильтрация пользователей ---
$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$filter_by_company = true;

$users = [];
$query = "
   SELECT 
     u.user_id,
     u.fullname,
     u.login,
     u.phone,
     u.email,
     CONCAT(
       a.region, ', ', a.city, ', ', a.street, ', дом ', a.home,
       IF(a.corpus IS NOT NULL AND a.corpus<>'', CONCAT(', корпус ',a.corpus),''),
       IF(a.flat_number IS NOT NULL AND a.flat_number<>'', CONCAT(', кв. ',a.flat_number),'')
     ) AS full_address
   FROM users u
   JOIN address a ON u.address_id=a.address_id
   WHERE a.management_company_id=?
";
$bind_types = "i";
$bind_vals  = [$management_company_id];

if ($search!=="") {
    $query .= " 
      AND (
          u.fullname LIKE ?
       OR u.login   LIKE ?
       OR u.phone   LIKE ?
       OR u.email   LIKE ?
       OR CONCAT(a.region,a.city,a.street,a.home,a.corpus,a.flat_number) LIKE ?
      )
    ";
    $bind_types.="sssss";
    $like_s = "%$search%";
    for ($i=0;$i<5;$i++){
       $bind_vals[]=$like_s;
    }
}
$query .= " ORDER BY u.fullname ASC";

$stmt_users = $conn->prepare($query);
$stmt_users->bind_param($bind_types, ...$bind_vals);
$stmt_users->execute();
$rsu = $stmt_users->get_result();
$users=[];
while($row=$rsu->fetch_assoc()){
    $users[]=$row;
}
$stmt_users->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Админ-панель ЖКХ</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: linear-gradient(135deg, #6dd5ed, #2193b0);
      color: #fff;
      margin: 0; padding: 0;
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
    .logout-btn:hover {
      background: darkred;
    }
    .content {
      padding:20px; text-align:center;
    }
    .mc-info {
      background:rgba(255,255,255,0.9); color:#000;
      padding:20px; margin:20px auto; border-radius:10px;
      max-width:1000px; width:100%;
    }
    .mc-info p { margin:5px 0; text-align:left; }
    .form-container {
      background:rgba(255,255,255,0.9); color:#000;
      padding:20px; margin:20px auto; border-radius:10px;
      max-width:1000px; width:100%;
    }
    .form-container input, .form-container button {
      width:48%; padding:10px; margin:10px 0; 
      border:1px solid #ccc; border-radius:5px; font-size:1em;
    }
    .form-container input:focus {
      border-color:#2193b0; outline:none;
    }
    .form-container button {
      background:#2193b0; color:#fff; border:none; cursor:pointer; transition: background 0.3s;
    }
    .form-container button:hover {
      background:#1a7a8f;
    }
    .user-management {
      background:rgba(255,255,255,0.9); color:#000;
      padding:20px; margin:20px auto; border-radius:10px;
      max-width:1500px; width:100%;
    }
    .user-management table {
      width:100%; border-collapse:collapse; margin-top:15px;
    }
    .user-management th, .user-management td {
      border:1px solid #ccc; padding:8px; text-align:left; background:#fff; color:#000;
    }
    .user-management th {
      background:#2193b0; color:#fff;
    }
    .search-form input[type="text"] {
      padding:8px; width:70%; border:1px solid #ccc; border-radius:5px; font-size:1em;
    }
    .search-form button {
      padding:8px 16px; background:#2193b0; color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:1em;
      margin-left:10px;
    }
    .message { color:yellow; font-weight:bold; }
  </style>

  <!-- (Client-side JS check) -->
  <script>
    // Функция валидации формы создания пользователя (клиентская проверка)
    function validateCreateUserForm() {
      // Берём ссылки на поля формы
      const form = document.getElementById('createUserForm');

      // Пробегаем по ряду обязательных полей
      const requiredFields = [
        'fullname',
        'login',
        'password',
        'date_of_birth',
        'phone',
        'email',
        'passport_series',
        'passport_number',
        'account_number',
        'INN',
        'region',
        'city',
        'street',
        'home',
        'post_index',
        'square',
        'residents_number'
      ];

      for (let i = 0; i < requiredFields.length; i++) {
        let fieldName = requiredFields[i];
        let fieldElem = form[fieldName];
        if (!fieldElem) continue; // вдруг не нашли
        
        // Убедимся, что не пустое
        if (fieldElem.value.trim() === '') {
          alert("Поле '" + fieldName + "' не может быть пустым (client-side check).");
          fieldElem.focus();
          return false;
        }
      }

      // Дополнительно можно сделать простую проверку формата телефона
      let phoneValue = form['phone'].value.trim();
      if (isNaN(phoneValue)) {
        alert("Телефон должен содержать только цифры (client-side check).");
        form['phone'].focus();
        return false;
      }

      // Если все минимальные проверки пройдены:
      return true;
    }
  </script>
</head>
<body>
<header>
  <h1>Админ-панель ЖКХ</h1>
  <form method="POST">
    <button type="submit" name="logout" class="logout-btn">Выйти</button>
  </form>
  <nav>
    <?php 
    $home_link = 'admin.php';
    ?>
    <a href="<?php echo $home_link; ?>" class="active">Главная</a>
    <a href="tariffs.php">Тарифы</a>
    <a href="pokazaniya.php">Показания</a>
    <a href="bills.php">Начисления</a>
    <a href="payments.php">Платежи</a>
    <a href="contact.php">Контакты</a>
    <a href="news.php">Новости</a>
  </nav>
</header>

<section class="content">
  <!-- Информация об УК -->
  <div class="mc-info">
    <h2>Информация об управляющей компании</h2>
    <p><strong>Название:</strong> <?php echo htmlspecialchars($mc_name);?></p>
    <p><strong>Телефон:</strong> <?php echo htmlspecialchars($mc_phone);?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($mc_email);?></p>
    <p><strong>Адрес:</strong> <?php echo htmlspecialchars($mc_address);?></p>
    <p><strong>Рабочие часы:</strong> <?php echo htmlspecialchars($mc_workhours);?></p>
    <p><strong>ИНН:</strong> <?php echo htmlspecialchars($mc_INN);?></p>
    <p><strong>КПП:</strong> <?php echo htmlspecialchars($mc_KPP);?></p>
    <p><strong>Расчетный счет:</strong> <?php echo htmlspecialchars($mc_payacc);?></p>
    <p><strong>БИК:</strong> <?php echo htmlspecialchars($mc_BIK);?></p>
    <p><strong>Корреспондентский счет:</strong> <?php echo htmlspecialchars($mc_coracc);?></p>
  </div>

  <!-- Форма создания нового пользователя -->
  <div class="form-container">
    <h2>Создать нового пользователя</h2>
    <?php if(!empty($message)):?>
      <p class="message"><?php echo $message;?></p>
    <?php endif;?>
    <!-- (Client-side JS check) -->
    <form method="POST" action="admin.php" id="createUserForm" onsubmit="return validateCreateUserForm();">
      <input type="text" name="fullname" placeholder="ФИО (до 150 символов)" required maxlength="150">
      <input type="text" name="login" placeholder="Логин (до 20 символов)" required maxlength="20">
      <input type="password" name="password" placeholder="Пароль (до 15 символов)" required maxlength="15">
      <input type="date" name="date_of_birth" placeholder="Дата рождения" required>
      <input type="text" name="phone" placeholder="Телефон (до 11 цифр)" required maxlength="11">
      <input type="email" name="email" placeholder="Email (до 30 символов)" required maxlength="30">
      <input type="text" name="passport_series" placeholder="Серия паспорта (4 цифры)" required maxlength="4">
      <input type="text" name="passport_number" placeholder="Номер паспорта (6 цифр)" required maxlength="6">
      <input type="text" name="account_number" placeholder="Номер лицевого счета (до 20 символов)" required maxlength="20">
      <input type="text" name="INN" placeholder="ИНН (12 цифр)" required maxlength="12">
      <input type="text" name="region" placeholder="Регион (до 35 символов)" required maxlength="35">
      <input type="text" name="city" placeholder="Город (до 20 символов)" required maxlength="20">
      <input type="text" name="street" placeholder="Улица (до 50 символов)" required maxlength="50">
      <input type="text" name="home" placeholder="Дом (до 4 символов)" required maxlength="4">
      <input type="text" name="corpus" placeholder="Корпус (до 2 символов)" maxlength="2">
      <input type="text" name="post_index" placeholder="Почтовый индекс (6 цифр)" required maxlength="6">
      <input type="text" name="flat_number" placeholder="Квартира (до 4 символов)" maxlength="4">
      <input type="number" step="0.01" name="square" placeholder="Площадь (кв.м)" required>
      <input type="number" name="residents_number" placeholder="Количество жильцов" required>

      <button type="submit" name="create_user">Создать пользователя</button>
    </form>
  </div>

  <!-- Блок списка пользователей -->
  <div class="user-management">
    <h2>Поиск и управление пользователями</h2>
    <form method="GET" class="search-form">
      <input type="text" name="search" 
             placeholder="Поиск по ФИО, логину, телефону, email или адресу"
             value="">
      <button type="submit">Найти</button>
      <label style="white-space:nowrap;">
        <input type="checkbox" name="filter_by_company" checked disabled>
        Показать только моих жителей
      </label>
    </form>
    <?php if(!empty($users)): ?>
      <table>
        <thead>
          <tr>
            <th>ФИО</th>
            <th>Логин</th>
            <th>Телефон</th>
            <th>Email</th>
            <th>Адрес</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($users as $u): ?>
            <tr>
              <td><?php echo htmlspecialchars($u['fullname']);?></td>
              <td><?php echo htmlspecialchars($u['login']);?></td>
              <td><?php echo htmlspecialchars($u['phone']);?></td>
              <td><?php echo htmlspecialchars($u['email']);?></td>
              <td><?php echo htmlspecialchars($u['full_address']);?></td>
              <td>
                <a href="edit_user.php?id=<?php echo $u['user_id'];?>">Редактировать</a>
                <a href="delete_user.php?id=<?php echo $u['user_id'];?>"
                   onclick="return confirm('Удалить пользователя?');">
                   Удалить
                </a>
              </td>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    <?php else: ?>
      <p>Пользователи не найдены.</p>
    <?php endif;?>
  </div>
</section>

<footer>
  <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>

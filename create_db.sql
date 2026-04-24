-- =====================
-- Скрипт для создания таблиц
-- =====================

-- Создание таблицы новостей
CREATE TABLE news (
    news_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(45) NOT NULL,
    content VARCHAR(2048) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы управляющей компании
CREATE TABLE management_company (
    management_company_id INT AUTO_INCREMENT PRIMARY KEY,
    management_company_name        VARCHAR(30)  NOT NULL UNIQUE,  -- Имя УК
    management_company_address     TEXT NOT NULL,
    management_company_login       VARCHAR(20)  NOT NULL,
    management_company_password    VARCHAR(15)  NOT NULL,
    management_company_phone       VARCHAR(11)  NOT NULL,
    management_company_email       VARCHAR(30)  NOT NULL UNIQUE,
    management_company_workhours   TEXT NOT NULL,
    management_company_INN         CHAR(10) NOT NULL UNIQUE,
    management_company_KPP         CHAR(9)  NOT NULL UNIQUE,
    management_company_payacc      CHAR(20) NOT NULL UNIQUE,      -- расчетный счет
    management_company_BIK         CHAR(9)  NOT NULL UNIQUE,
    management_company_coracc      CHAR(20) NOT NULL UNIQUE       -- корр. счет
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы поставщиков ресурсов
CREATE TABLE resource_supplier (
    resource_supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    resource_supplier_name     VARCHAR(60) NOT NULL,
    resource_supplier_address  TEXT NOT NULL,
    resource_supplier_phone    VARCHAR(11) NOT NULL,
    resource_supplier_email    VARCHAR(30) NOT NULL,
    resource_supplier_website  VARCHAR(70) NOT NULL,      -- Ссылка на вебсайт
    resource_supplier_INN      CHAR(10) NOT NULL UNIQUE,
    resource_supplier_KPP      CHAR(9)  NOT NULL UNIQUE,
    resource_supplier_BIK      CHAR(9)  NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы адресов
CREATE TABLE address (
    address_id INT AUTO_INCREMENT PRIMARY KEY,
    region         VARCHAR(35) NOT NULL,
    city           VARCHAR(20) NOT NULL,
    street         VARCHAR(50) NOT NULL,
    home           VARCHAR(4)  NOT NULL,
    corpus         VARCHAR(2),
    post_index     VARCHAR(6)  NOT NULL,
    flat_number    VARCHAR(4),
    square         NUMERIC(8,2) NOT NULL,
    residents_number INT NOT NULL,
    management_company_id INT NOT NULL,
    CONSTRAINT fk_address_management_company
        FOREIGN KEY (management_company_id)
        REFERENCES management_company(management_company_id) 
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы пользователей
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(150)  NOT NULL,       -- ФИО пользователя
    login VARCHAR(20) NOT NULL UNIQUE,     -- Логин
    password VARCHAR(15) NOT NULL,         -- Пароль
    date_of_birth DATE NOT NULL,           -- Дата рождения
    phone VARCHAR(11) NOT NULL,            -- Телефон
    email VARCHAR(30) NOT NULL,
    passport_series CHAR(4) NOT NULL,      -- Серия паспорта
    passport_number CHAR(6) NOT NULL UNIQUE,  -- Номер паспорта
    account_number VARCHAR(20) NOT NULL UNIQUE, -- Номер лицевого счета
    account_balance NUMERIC(20,2) DEFAULT 0,    -- Баланс л/с
    INN CHAR(12) NOT NULL UNIQUE,               -- ИНН
    address_id INT NOT NULL,
    CONSTRAINT fk_users_address
        FOREIGN KEY (address_id)
        REFERENCES address(address_id) 
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы тарифов
CREATE TABLE tariffs (
    tariff_id INT AUTO_INCREMENT PRIMARY KEY,
    service_type ENUM('ХВС', 'ГВС', 'Электроснабжение', 'Отопление', 'Газ', 'Вывоз мусора', 'Фонд капитального ремонта', 'Содержание жилого помещения') NOT NULL,
    price NUMERIC(10,2) NOT NULL,         -- Тариф
    measurement_unit VARCHAR(20),
    resource_supplier_id INT,
    management_company_id INT,
    normative DECIMAL(10,2) NULL,
    CONSTRAINT fk_tariffs_resource_supplier
        FOREIGN KEY (resource_supplier_id)
        REFERENCES resource_supplier(resource_supplier_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_tariffs_management_company
        FOREIGN KEY (management_company_id)
        REFERENCES management_company(management_company_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы счетчиков (приборов учета)
CREATE TABLE meter (
    meter_id INT AUTO_INCREMENT PRIMARY KEY,
    meter_serial_number VARCHAR(10) NOT NULL UNIQUE,
    resource_type ENUM('ХВС', 'ГВС', 'Электричество', 'Отопление', 'Газ') NOT NULL,
    installation_date DATE NOT NULL,
    last_check DATE NOT NULL,
    address_id INT NOT NULL,
    resource_supplier_id INT NOT NULL,
    meter_type ENUM('общедомовой', 'индивидуальный') NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_meter_address
        FOREIGN KEY (address_id)
        REFERENCES address(address_id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_meter_resource_supplier
        FOREIGN KEY (resource_supplier_id)
        REFERENCES resource_supplier(resource_supplier_id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы показаний счетчиков
CREATE TABLE meter_readings (
    reading_id INT AUTO_INCREMENT PRIMARY KEY,
    meter_id INT NOT NULL,
    user_id INT,
    value NUMERIC(10,2),
    data TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_readings_meter
        FOREIGN KEY (meter_id)
        REFERENCES meter(meter_id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_readings_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Создание таблицы счетов
CREATE TABLE bills (
    bill_id INT AUTO_INCREMENT PRIMARY KEY,
    tariff_id INT NOT NULL,   -- Ссылка на тариф (тип услуги/тариф)
    consumption NUMERIC(10,2),
    receiving_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- Время выставления счета
    payment_date DATE DEFAULT NULL,                               -- Дата фактической оплаты (может быть NULL, если не оплачено)
    deadline_date DATE NOT NULL,                                  -- Крайний срок оплаты
    user_id INT NOT NULL,                                         -- Кому выставлен счет
    meter_id INT,                                                 -- Ссылка на счетчик (может быть NULL)
    last_update TIMESTAMP NULL DEFAULT NULL,
    cost NUMERIC(12,2),
    penalty NUMERIC(12,2),
    total_amount NUMERIC(12,2),
    billing_period VARCHAR(10),                                   -- Период "mm гг" или "YYYY-MM"
    CONSTRAINT fk_bills_tariffs
        FOREIGN KEY (tariff_id)
        REFERENCES tariffs(tariff_id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_bills_users
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_bills_meter
        FOREIGN KEY (meter_id)
        REFERENCES meter(meter_id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

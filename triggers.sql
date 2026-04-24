-- =====================
-- Скрипт для создания триггеров
-- =====================
DELIMITER $$

-- Триггер для таблицы tariffs (проверка при INSERT/UPDATE)
CREATE TRIGGER tariffs_check
BEFORE INSERT ON tariffs
FOR EACH ROW
BEGIN
    -- Проверка для типа услуги 'Содержание жилого помещения'
    IF NEW.service_type = 'Содержание жилого помещения' THEN
        -- Проверяем, что только `management_company_id` заполнено
        IF NEW.resource_supplier_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'Для "Содержание жилого помещения" должен быть заполнен только management_company_id, а не resource_supplier_id';
        END IF;
    ELSE
        -- Для всех остальных типов услуг проверяем, что только `resource_supplier_id` заполнено
        IF NEW.management_company_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'Для данного типа услуги должен быть заполнен только resource_supplier_id, а не management_company_id';
        END IF;
    END IF;
END$$


-- Триггер для таблицы tariffs (проверка при INSERT, что ровно одно из двух полей заполнено)
CREATE TRIGGER tariffs_insert
BEFORE INSERT ON tariffs
FOR EACH ROW
BEGIN
    IF (NEW.resource_supplier_id IS NOT NULL AND NEW.management_company_id IS NOT NULL)
       OR (NEW.resource_supplier_id IS NULL AND NEW.management_company_id IS NULL) THEN
        SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'Только один из resource_supplier_id или management_company_id должен быть заполнен';
    END IF;
END$$


-- Триггер для таблицы bills (установка deadline_date, penalty, total_amount при создании записи)
CREATE TRIGGER bills_before_insert
BEFORE INSERT ON bills
FOR EACH ROW
BEGIN
    -- Срок оплаты = 10 дней от момента выставления
    SET NEW.deadline_date = DATE_ADD(NEW.receiving_date, INTERVAL 10 DAY);

    -- Пеня при создании = 0
    SET NEW.penalty = 0;

    -- Начальное total = cost + penalty (0)
    SET NEW.total_amount = NEW.cost;
END$$


-- Триггер для таблицы bills (расчет пени, если просрочено)
CREATE TRIGGER bills_penalty
BEFORE UPDATE ON bills
FOR EACH ROW
BEGIN
    DECLARE overdue_days INT DEFAULT 0;

    -- Если платеж не внесён (payment_date IS NULL):
    IF NEW.payment_date IS NULL THEN
        -- Смотрим, не прошла ли дата deadline_date
        IF NOW() > NEW.deadline_date THEN
            SET overdue_days = DATEDIFF(NOW(), NEW.deadline_date);
            
            -- Если почему-то получилось отрицательное — обнулим
            IF overdue_days < 0 THEN
                SET overdue_days = 0;
            END IF;

            -- Пеня = (cost * 0.001) * overdue_days (пример: 0.1% за день просрочки)
            SET NEW.penalty = NEW.cost * 0.001 * overdue_days;
            SET NEW.total_amount = NEW.cost + NEW.penalty;
        ELSE
            -- Если срок оплаты не наступил или сегодня deadline_date
            SET NEW.penalty = 0;
            SET NEW.total_amount = NEW.cost;
        END IF;
    END IF;
END$$

DELIMITER ;

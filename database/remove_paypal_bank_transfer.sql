-- Run once on existing databases after removing PayPal & Bank Transfer.
UPDATE `payments` SET `payment_method` = 'cash' WHERE `payment_method` IN ('paypal', 'bank_transfer');
ALTER TABLE `payments` MODIFY `payment_method` ENUM('payhere', 'cash') NOT NULL;

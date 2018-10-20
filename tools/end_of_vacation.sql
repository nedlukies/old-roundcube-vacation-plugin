DELIMITER //

CREATE PROCEDURE spDisableVacation()
BEGIN
    UPDATE alias, vacation SET alias.goto = alias.address, vacation.active=0 WHERE alias.address = vacation.email AND alias.goto LIKE CONCAT(REPLACE(alias.address, '@', '#'), '%') AND vacation.activeuntil <= now();
END;
//

DELIMITER ;

SET GLOBAL event_scheduler = ON;

CREATE EVENT evDisableVacation
    ON SCHEDULE EVERY 1 MINUTE
DO 
  call spDisableVacation();

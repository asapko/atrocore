<?php
/*
 * This file is part of EspoCRM and/or AtroCore.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2019 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * AtroCore is EspoCRM-based Open Source application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 *
 * AtroCore as well as EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroCore as well as EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word
 * and "AtroCore" word.
 */

declare(strict_types=1);

namespace Treo\Migrations;

use Espo\Console\Cron;
use Treo\Core\Migration\Base;

class V1Dot4Dot1 extends Base
{
    public function up(): void
    {
        $this->execute("DELETE FROM `queue_item` WHERE 1");
        $this->execute("ALTER TABLE `queue_item` CHANGE `sort_order` sort_order INT AUTO_INCREMENT NOT NULL UNIQUE COLLATE utf8mb4_unicode_ci");
        $this->execute("CREATE UNIQUE INDEX UNIQ_BA4B6DE845AFA4EA ON `queue_item` (sort_order)");
        $this->execute("ALTER TABLE `queue_item` ADD priority VARCHAR(255) DEFAULT 'Normal' COLLATE utf8mb4_unicode_ci");
        file_put_contents(Cron::DAEMON_KILLER, '1');
    }

    public function down(): void
    {
        $this->execute("DROP INDEX sort_order ON `queue_item`");
        $this->execute("ALTER TABLE `queue_item` CHANGE `sort_order` sort_order INT DEFAULT '0' COLLATE utf8mb4_unicode_ci");
        $this->execute("ALTER TABLE `queue_item` DROP priority");
        file_put_contents(Cron::DAEMON_KILLER, '1');
    }

    protected function execute(string $sql): void
    {
        try {
            $this->getPDO()->exec($sql);
        } catch (\Throwable $e) {
            // ignore all
        }
    }
}

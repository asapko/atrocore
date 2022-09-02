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
 *
 * This software is not allowed to be used in Russia and Belarus.
 */

declare(strict_types=1);

namespace Treo\Migrations;

use Treo\Core\Migration\Base;

class V1Dot4Dot91 extends Base
{
    public function up(): void
    {
        $this->execute("DROP INDEX IDX_SOURCE_ID ON attachment");
        $this->execute("ALTER TABLE attachment DROP source_id");

        $this->execute("DROP INDEX IDX_PARENT ON attachment");
        $this->execute("ALTER TABLE attachment DROP parent_id, DROP parent_type");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE attachment ADD source_id VARCHAR(255) DEFAULT NULL COLLATE `utf8mb4_unicode_ci`");
        $this->execute("CREATE INDEX IDX_SOURCE_ID ON attachment (source_id)");

        $this->execute("ALTER TABLE attachment ADD parent_id VARCHAR(24) DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, ADD parent_type VARCHAR(100) DEFAULT NULL COLLATE `utf8mb4_unicode_ci`");
        $this->execute("CREATE INDEX IDX_PARENT ON attachment (parent_type, parent_id)");
    }

    protected function execute(string $query): void
    {
        try {
            $this->getPDO()->exec($query);
        } catch (\Throwable $e) {
        }
    }
}

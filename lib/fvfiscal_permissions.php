<?php
/* Copyright (C) 2025           SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Centralized permission identifiers for the FvFiscal module.
 */
final class FvFiscalPermissions
{
    /** Module identifier used by Dolibarr's ACL system. */
    public const MODULE = 'fvfiscal';

    /** Permission prefix for operations around Focus batches. */
    public const BATCH = 'batch';

    /** ACL suffix that grants read-only access to Focus batch data. */
    public const BATCH_READ = 'read';

    /** ACL suffix that grants write access to Focus batch data. */
    public const BATCH_WRITE = 'write';

    private function __construct()
    {
        // Prevent instantiation.
    }
}

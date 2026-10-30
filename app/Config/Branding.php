<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Product identity and authorship shown in the shared page footer
 * (app/Views/partials/app_footer.php) and in the documentation.
 *
 * One place to change it: every page that renders the footer reads these values.
 */
class Branding extends BaseConfig
{
    public string $productName = 'Micron OS';

    public string $authorName = 'Prashant Arbat';

    public string $authorEmail = 'prashant.arbat@gmail.com';

    /** Public project home, linked from the footer on every page. */
    public string $projectUrl = 'https://github.com/prashantarbat/micronos';
}

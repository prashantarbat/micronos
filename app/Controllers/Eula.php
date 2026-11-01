<?php

namespace App\Controllers;

use App\Libraries\DefaultCredentials;
use App\Libraries\Eula as Agreement;
use App\Libraries\IntendedUrl;
use App\Models\SystemSettingsModel;

/**
 * The licence agreement page every sign-in lands on until an administrator
 * accepts the current version (see App\Filters\AuthFilter).
 */
class Eula extends BaseController
{
    public function index(): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $settings = (new SystemSettingsModel())->getSettings();
        $appearance = config('Appearance');
        $theme = $appearance->resolveTheme($settings['theme'] ?? null);

        return view('eula/index', [
            'title'       => 'Licence Agreement — ' . \App\Libraries\AppVersion::getProjectName(),
            'text'        => Agreement::text(),
            'version'     => Agreement::VERSION,
            'accepted'    => Agreement::isAccepted($settings),
            'acceptedAt'  => (string) ($settings['eula_accepted_at'] ?? ''),
            'acceptedBy'  => (string) ($settings['eula_accepted_by'] ?? ''),
            'canAccept'   => (bool) session()->get('is_superadmin'),
            'appTheme'    => $theme,
            'appMode'     => $appearance->isLight($theme) ? 'light' : 'dark',
            'appIsLight'  => $appearance->isLight($theme),
        ]);
    }

    public function accept(): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! session()->get('is_superadmin')) {
            return redirect()->to(base_url('eula'))->with('error', 'Only an administrator can accept the licence agreement for this appliance.');
        }
        if ($this->request->getPost('agree') !== '1') {
            return redirect()->to(base_url('eula'))->with('error', 'Tick "I have read and accept" to continue.');
        }

        $username = (string) session()->get('username');
        (new SystemSettingsModel())->update(1, [
            'eula_version'     => Agreement::VERSION,
            'eula_accepted_at' => date('Y-m-d H:i:s'),
            'eula_accepted_by' => $username,
        ]);
        session()->set(Agreement::SESSION_FLAG, Agreement::VERSION);
        log_message('notice', "[Eula] {$username} accepted the Micron OS licence agreement v" . Agreement::VERSION);

        if (session()->get(DefaultCredentials::SESSION_FLAG)) {
            return redirect()->to(base_url('profile'))->with('warning',
                'This account still uses the factory default password. Choose a new password to continue.');
        }

        return redirect()->to(IntendedUrl::take(session()));
    }

    public function decline(): \CodeIgniter\HTTP\RedirectResponse
    {
        log_message('notice', '[Eula] ' . session()->get('username') . ' declined the licence agreement; signed out');

        return redirect()->to(base_url('auth/logout'));
    }
}

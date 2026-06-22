<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Notifications\WelcomeUserNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Password;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Send the branded welcome email after the user is created. afterCreate runs
     * ONLY on creation (EditUser is a separate page), so editing a user never
     * re-sends it. The CTA is a single-use "set your password" link built from
     * the password broker + Filament's reset-page URL, so the new user sets their
     * own password. A mail failure must not fail user creation — the record is
     * already saved — so it's caught and surfaced as a warning toast.
     */
    protected function afterCreate(): void
    {
        $user = $this->record;

        try {
            $token = Password::broker(Filament::getAuthPasswordBroker())->createToken($user);
            $url = Filament::getResetPasswordUrl($token, $user);

            $user->notify(new WelcomeUserNotification($url));
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('User created, but the welcome email could not be sent')
                ->body('You can send them a password-reset link from the login page instead.')
                ->warning()
                ->send();
        }
    }
}

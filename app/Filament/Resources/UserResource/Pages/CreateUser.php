<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Notifications\WelcomeUserNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * No password is collected on create — the new user sets their own via the
     * welcome email's "set your password" link. Store a random, UNUSABLE
     * placeholder so the column is never null and the admin never has to type (or
     * communicate) a password. We store an already-hashed random value; the 'hashed'
     * cast detects it is already hashed (Hash::isHashed) and leaves it as-is.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Hash::make(Str::random(40));

        return $data;
    }

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

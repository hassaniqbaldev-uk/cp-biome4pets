<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\AdminFormatting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/**
 * Super-Admin-only user management: this is how a Super Admin creates Admins and
 * assigns roles (Super Admin vs Admin). canAccess() gates the whole resource —
 * Filament returns 403 on any of its routes for non-super-admins, and it's hidden
 * from the nav.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'System';

    // Between Settings (10) and Report an Issue (20).
    protected static ?int $navigationSort = 15;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('User')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->options(User::ROLES)
                            ->default(User::ROLE_ADMIN)
                            ->required()
                            ->native(false)
                            ->helperText('Super Admins can also reach Settings (API keys / integrations) and Report an Issue.'),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            // Required only when creating; on edit, blank = keep
                            // the current password. The User model casts password
                            // to 'hashed', so a plaintext value is hashed on save.
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255)
                            // M3: enforce the strong-password baseline (Password::default()
                            // configured in AppServiceProvider). On edit a blank value is
                            // allowed (keeps the current password) — 'nullable' short-circuits
                            // the rule so we only validate a newly typed password.
                            ->rules(fn (string $operation): array => $operation === 'create'
                                ? [Password::default()]
                                : ['nullable', Password::default()])
                            ->helperText('Min 12 characters with upper & lower case and a number. Leave blank when editing to keep the current password.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::ROLES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => $state === User::ROLE_SUPER_ADMIN ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date(AdminFormatting::DATE)
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Dniccum\Linear\Concerns\HasLinearConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Workbench\Database\Factories\UserFactory;

/**
 * The owner of a Linear connection in the workbench and in the package's own
 * tests.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasLinearConnection;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @var list<string>
     */
    protected $appends = ['linear_connected'];

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}

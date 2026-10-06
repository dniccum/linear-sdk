<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Dniccum\Linear\Concerns\CreatesLinearIssues;
use Illuminate\Database\Eloquent\Model;

/**
 * Uses the trait but has no `user` relation, so it has no owner.
 *
 * @property int $id
 * @property string $body
 */
class Note extends Model
{
    use CreatesLinearIssues;

    protected $table = 'replies';

    /**
     * @var list<string>
     */
    protected $fillable = ['ticket_id', 'body'];
}

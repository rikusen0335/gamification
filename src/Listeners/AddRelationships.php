<?php
/**
 *  This file is part of reflar/gamification.
 *
 *  Copyright (c) ReFlar.
 *
 *  http://reflar.io
 *
 *  For the full copyright and license information, please view the license.md
 *  file that was distributed with this source code.
 */

namespace Reflar\gamification\Listeners;

use Flarum\Api\Controller;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Api\Serializer\UserBasicSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Core\Post;
use Flarum\Core\User;
use Flarum\Event\ConfigureApiController;
use Flarum\Event\GetApiRelationship;
use Flarum\Event\GetModelRelationship;
use Flarum\Event\PrepareApiAttributes;
use Flarum\Event\PrepareApiData;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Reflar\gamification\Rank;
use Reflar\gamification\Api\Serializers\RankSerializer;

class AddRelationships
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        $events->listen(GetModelRelationship::class, [$this, 'getModelRelationship']);
        $events->listen(PrepareApiData::class, [$this, 'loadRanksRelationship']);
        $events->listen(GetApiRelationship::class, [$this, 'getApiAttributes']);
        $events->listen(PrepareApiAttributes::class, [$this, 'prepareApiAttributes']);
        $events->listen(ConfigureApiController::class, [$this, 'includeLikes']);
    }

    /**
     * @param GetModelRelationship $event
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany|null
     */
    public function getModelRelationship(GetModelRelationship $event)
    {
        if ($event->isRelationship(Post::class, 'upvotes')) {
            return $event->model->belongsToMany(User::class, 'posts_votes', 'post_id', 'user_id', 'upvotes')->where('type', 'Up');
        }

        if ($event->isRelationship(Post::class, 'downvotes')) {
            return $event->model->belongsToMany(User::class, 'posts_votes', 'post_id', 'user_id', 'downvotes')->where('type', 'Down');
        }

        if ($event->isRelationship(User::class, 'ranks')) {
            return $event->model->belongsToMany(Rank::class, 'users_ranks');
        }
    }

    /**
     * @param GetApiRelationship $event
     *
     * @return \Tobscure\JsonApi\Relationship|null
     */
    public function getApiAttributes(GetApiRelationship $event)
    {
        if ($event->isRelationship(PostSerializer::class, 'upvotes')) {
            return $event->serializer->hasMany($event->model, UserBasicSerializer::class, 'upvotes');
        }

        if ($event->isRelationship(PostSerializer::class, 'downvotes')) {
            return $event->serializer->hasMany($event->model, UserBasicSerializer::class, 'downvotes');
        }

        if ($event->isRelationship(ForumSerializer::class, 'ranks') || $event->isRelationship(UserSerializer::class, 'ranks')) {
            return $event->serializer->hasMany($event->model, RankSerializer::class, 'ranks');
        }
    }


    /**
     * @param PrepareApiData $event
     */
    public function loadRanksRelationship(PrepareApiData $event)
    {
        if ($event->isController(Controller\ShowForumController::class)) {
            $event->data['ranks'] = Rank::get();
        }
    }

    /**
     * @param PrepareApiAttributes $event
     */
    public function prepareApiAttributes(PrepareApiAttributes $event)
    {
        if ($event->isSerializer(UserSerializer::class)) {
            $event->attributes['Points'] = $event->model->votes;
        }
        if ($event->isSerializer(ForumSerializer::class)) {
            $event->attributes['DefaultRank'] = $this->settings->get('reflar.gamification.defaultRank');
            $event->attributes['IconName'] = $this->settings->get('reflar.gamification.iconName');
            $event->attributes['autoUpvote'] = $this->settings->get('reflar.gamification.voteColor');
            $event->attributes['RankHolder'] = $this->settings->get('reflar.gamification.rankHolder');
        }
        if ($event->isSerializer(DiscussionSerializer::class)) {
            $event->attributes['canVote'] = (bool) $event->actor->can('vote', $event->model);
            $event->attributes['canSeeVotes'] = (bool) $event->actor->can('canSeeVotes', $event->model);
        }
    }

    /**
     * @param ConfigureApiController $event
     */
    public function includeLikes(ConfigureApiController $event)
    {
        if ($event->isController(Controller\ListUsersController::class)
            || $event->isController(Controller\ShowUserController::class)
            || $event->isController(Controller\CreateUserController::class)
            || $event->isController(Controller\UpdateUserController::class)) {
            $event->addInclude('ranks');
        }
        if ($event->isController(Controller\ShowDiscussionController::class)) {
            $event->addInclude(['posts.upvotes', 'posts.downvotes', 'posts.user.ranks']);
        }
        if ($event->isController(Controller\ListPostsController::class)
            || $event->isController(Controller\ShowPostController::class)
            || $event->isController(Controller\CreatePostController::class)
            || $event->isController(Controller\UpdatePostController::class)) {
            $event->addInclude(['upvotes', 'downvotes', 'user.ranks']);
        }
        if ($event->isController(Controller\ShowForumController::class)) {
            $event->addInclude('ranks');
        }
    }
}

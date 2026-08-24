<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use johnhenry\linkaudit\LinkAudit;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * The one thing the Overview tour has to tell the server.
 *
 * Nothing here is an admin job. The tour explains what the counts mean to the
 * person reading them, so every reader gets it once, and this only has to
 * remember that they have had it. The permission is the same one the reports
 * want, which {@see BaseController} already asks for.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class TourController extends BaseController
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Records that this reader has met the Overview tour.
     *
     * Posted when the tour is finished and when it is dismissed alike, so a
     * reader who closed it after the first step is not shown it again tomorrow.
     *
     * @return Response The outcome, as JSON.
     * @throws BadRequestHttpException If the request does not accept JSON.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws MethodNotAllowedHttpException If the request is not a POST.
     * @throws InvalidConfigException If the tour service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSeen(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return $this->asJson(['success' => false]);
        }

        LinkAudit::$plugin->getTourService()->markSeen($user);

        return $this->asJson(['success' => true]);
    }
}

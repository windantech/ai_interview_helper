<?php

declare(strict_types=1);

/*
 * POST /api/save-job.php  JSON/form: {id?, title, company, industry, location, description, main_skills, interview_type, seniority}
 *                         or {sample: 1} to add the demo "Project Manager" job.
 */

define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Api;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Job;
use App\Models\Settings;

Api::handle(function (): void {
    $user = Api::postGuard();
    $userId = (int) $user['id'];
    $in = Api::input();

    if (Job::count($userId) >= 100 && empty($in['id'])) {
        throw new HttpException(422, 'You have reached the maximum of 100 saved jobs. Delete some to add more.');
    }

    if (!empty($in['sample'])) {
        $id = Job::create($userId, Job::sample());
        Settings::update($userId, ['active_job_id' => $id]);
        Response::success(['job' => Job::findForUser($id, $userId), 'created' => true], 201);
    }

    $v = Validator::make($in, [
        'title'          => 'required|max:160',
        'company'        => 'max:160',
        'industry'       => 'max:120',
        'location'       => 'max:120',
        'description'    => 'required|min:30|max:30000',
        'main_skills'    => 'max:500',
        'interview_type' => 'required|in:' . implode(',', array_keys(options_for('interview_type'))),
        'seniority'      => 'required|in:' . implode(',', array_keys(options_for('seniority'))),
    ], ['title' => 'Job title', 'description' => 'Job description', 'main_skills' => 'Main skills']);
    if ($v->fails()) {
        throw new HttpException(422, $v->firstError(), ['errors' => $v->errors()]);
    }
    $data = $v->validated();

    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        if (!Job::findForUser($id, $userId)) {
            throw new HttpException(404, 'Job not found.');
        }
        Job::update($id, $userId, $data);
        $created = false;
    } else {
        $id = Job::create($userId, $data);
        $created = true;
    }
    if ($created || !empty($in['make_active'])) {
        Settings::update($userId, ['active_job_id' => $id]);
    }
    Response::success(['job' => Job::findForUser($id, $userId), 'created' => $created], $created ? 201 : 200);
});

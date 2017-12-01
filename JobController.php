<?php

namespace Laraspace\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use Laraspace\Mail\JobDeclined;
use Storage;
use Illuminate\Validation\Rule;
use Laraspace\Models\Job;
use Laraspace\Models\Banner;
use Laraspace\Mail\JobPublished;
use Illuminate\Support\Facades\Mail;

class JobController extends Controller
{
    /**
     * Display available jobs.
     * @return view list of available jobs.
     */
    public function index() {
        if(Auth::user()->hasRole(['admin','developer'])) {
            $jobs = Job::all();
        } else {
            $jobs = Auth::user()->jobs()->get();
        }
        return view('jobs.index', compact('jobs'));
    }

    /**
     * Stores new job.
     * @return json Confirmation massage.
     */
    public function store() {
        $this->validate(request(), [
            'title' => 'required|min:5',
            'description' => 'required|min:100',
            'rate' => 'required|numeric|required_with:category|min_value:'.request('category'),
            'expeditate_rate' => 'numeric|required_with:category|min_value:'.request('category'),
            'minWords' => 'required|numeric|min:0',
            'revision_number' => 'required|numeric|min:0',
            'delivery_guarantee' => 'required|numeric|min:1|required_with:rate',
            'delivery_expeditate' => 'numeric|min:1|required_with:expeditate_rate',
            'category' => [
                'required',
                'numeric',
                Rule::exists('categories', 'id')->where(function($query) {
                    return $query->where('status', 'active');
                }),
            ],
        ]);
        if(Auth::user()->isPaypalSet()) {
            if(Auth::user()->verified) {
                $job = Job::create([
                    'title'               => request('title'),
                    'uuid'                => md5(microtime()),
                    'description'         => request('description'),
                    'rate'                => request('rate'),
                    'expeditate_rate'     => request('expeditate_rate'),
                    'minWords'            => request('minWords'),
                    'revision_number'     => request('revision_number'),
                    'delivery_guarantee'  => request('delivery_guarantee'),
                    'delivery_expeditate' => request('delivery_expeditate'),
                    'category_id'         => request('category'),
                    'user_id'             => Auth::user()->id
                ]);

                $banners = request('banner');
                $files   = Banner::find($banners);
                if ($files) {
                    foreach ($files as $file) {
                        $file->update([
                            'job_id' => $job->id
                        ]);
                    }
                    $this->clean();
                }
            } else {
                return response('Email not confirmed!', 403);
            }
        } else {
            return response('Paypal account not set!', 403);
        }
    }

    /**
     * Filter jobs pending confirmation,
     * @return view list of jobs to be confirmed.
     */
    public function pending() {
        $jobs = Job::inactive()->get();
        return view('jobs.activation', compact('jobs'));
    }

    /**
     * Show job object from id
     * @param  Job    $job the job id.
     * @return Job
     */
    public function show(Job $job) {
        $job->category_name = $job->category()->first()->title;
        $job->image = $job->getBanner(318,180);
        $job->hasPaypal = $job->user()->first()->isPaypalSet();
        return $job;
    }

    /**
     * Activate job
     * @param  Job    $job the job id.
     * @return Redirect
     */
    public function activate(Job $job) {

        if( $job->user()->first()->id == Auth::user()->id || Auth::user()->hasRole('admin') || Auth::user()->hasRole('developer')) {
            $job->activated();
            Mail::to($job->user()->first())->send(new JobPublished($job));
            flash()->success('Job published successfully!');
        } else {
            flash()->error('Permission denied!');
        }

        return redirect()->route('jobs.activate');
    }

    /**
     * Decline job posting
     * @param  Job    $job the job id.
     * @return Redirect
     */
    public function decline(Job $job) {

        if( $job->user()->first()->id == Auth::user()->id || Auth::user()->hasRole('admin') || Auth::user()->hasRole('developer')) {
            $job->declined();
            $reason = request('reason');
            Mail::to($job->user()->first())->send(new JobDeclined($job, $reason));
            flash()->success('Job declined successfully!');
        } else {
            flash()->error('Permission denied!');
        }

        return redirect()->route('jobs.activate');
    }

    /**
     * Delete job posting.
     * @param  Job    $job the job ID
     * @return Redirect
     */
    public function delete(Job $job) {
        if( $job->user()->first()->id == Auth::user()->id || Auth::user()->hasRole('admin') || Auth::user()->hasRole('developer')) {
            $job->delete();
            flash()->success('Job deleted successfully!');
        } else {
            flash()->error('Permission denied!');
        }

        return redirect()->route('jobs.activate');
    }

    /**
     * Load job profile
     * @param  int $uuid job unique ID
     * @return view       the job profile page.
     */
    public function profile($uuid) {
        $job = Job::active()->where('uuid', $uuid)->first();
        if($job) {
            return view('jobs.profile', compact('job'));
        } else {
            return view('errors.404');
        }
    }

    /**
     * Display job editor page.
     * @param  int $uuid job unique id.
     * @return View       the editor page.
     */
    public function edit($uuid) {
        $job = Job::where('uuid', $uuid)->first();
        if($job) {
            if($job->user_id === Auth::user()->id || Auth::user()->hasRole(['admin', 'developer'])) {
                return view('jobs.editor', compact('job'));
            }
        }
        return view('errors.404');
    }

    /**
     * Save changes made on job profile.
     * @param  int $uuid job unique id.
     * @return Redirect
     */
    public function save($uuid) {
        $job = Job::where('uuid', $uuid)->first();
        if($job) {
            if($job->user_id === Auth::user()->id || Auth::user()->hasRole(['admin', 'developer'])) {
                $this->validate(request(), [
                    'title'               => 'required|min:5',
                    'description'         => 'required|min:100',
                    'rate'                => 'required|numeric|min_value:' . $job->category()->first()->id,
                    'expeditate_rate'     => 'numeric|min_value:' . $job->category()->first()->id,
                    'minWords'            => 'required|numeric',
                    'delivery_guarantee'  => 'required|numeric|min:1|required_with:rate',
                    'delivery_expeditate' => 'numeric|min:1|required_with:expeditate_rate',
                    'revision_number'     => 'required|numeric|min:0'
                ]);

                $job->update([
                    'title'               => request('title'),
                    'description'         => request('description'),
                    'rate'                => request('rate'),
                    'expeditate_rate'     => request('expeditate_rate'),
                    'minWords'            => request('minWords'),
                    'delivery_guarantee'  => request('delivery_guarantee'),
                    'delivery_expeditate' => request('delivery_expeditate'),
                    'revision_number'     => request('revision_number'),
                    'status'              => 'inactive'
                ]);
                flash()->success('Changes saved!');

                return redirect()->route('jobs.dashboard');
            }
        }
        flash()->error('Could not save changes!');
        return back();
    }

    /**
     * Removes image associated to job.
     * @return void
     */
    private function clean() {
        $banners = Banner::where('job_id', null)->get();
        foreach($banners as $banner) {
            Storage::delete($banner->link);
            $banner->delete();
        }
    }
}

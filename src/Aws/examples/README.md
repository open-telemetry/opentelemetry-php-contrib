
# Tracing with AWS Sample Apps

## Overview

![Current Version](https://img.shields.io/github/v/tag/open-telemetry/opentelemetry-php)

This is a getting started guide for the example applications found in the AWS contrib folder. This walkthrough covers prerequisite, installations, how to run the applications, and viewing the traces on X-Ray. Before reading this guide, you should familiarize with distributed tracing and the basics of OpenTelemetry. To learn more about getting started with OpenTelemetry PHP, see the OpenTelemetry developer documentation.

## About the Sample Apps

Currently, the ability to instrument an application automatically does not exist, so manually instrumenting the apps was necessary. In both of the applications, creation of a tracer, generation of spans, propagation of contexts, and closing spans was implemented manually. Both of these applications are console applications that export trace data to the OTEL Collector which is then exported to AWS X-Ray.

### Aws Client App

The first sample app in its implementation is creation of a span, then a child span, which is then populated in an HTTP header that makes a request to either aws.amazon.com (http://aws.amazon.com/) or the AWS SDK. The application will prompt you for input on which action you would like to take, and subsequently prints out the trace ID.

### Sample App 2

The second application is a more robust example of how a real-world application may make a request to different services. A main application will make a call to two different microservices called Service1 and Service2. Before calling either of the services, the span context is injected into a carrier that is then taken to the service. Then, the services will extract the context from the carrier and create a new span based upon it. After the services are concluded, the child spans are ended and then the main root span is ended in the main application.

## Prerequisites

The following downloads are necessary for running either of the above specified applications. The two repositories below can be downloaded anywhere on your machine. If you are having an issue with not being able to access the collector with your credentials, clone the aws-otel-collector in your root directory.

Download Docker here: https://docs.docker.com/get-docker/

Clone locally the opentelemetry-php-contrib here: https://github.com/open-telemetry/opentelemetry-php-contrib

Clone locally the aws-otel-collector here: https://github.com/aws-observability/aws-otel-collector

## Set Up

### Docker

Make sure Docker Desktop is running.

### AWS Access Keys

Now make sure that your AWS access keys are configured in your root directory. To see if your credentials are setup run the following command:

`cat .aws/credentials`

If they are not configured, please visit [here](https://docs.aws.amazon.com/cli/latest/userguide/cli-chap-configure.html) for instructions on how to set them up.

### Grpc Installation

In your root directory, run the following commands. These commands will take a while to install all the necessary components.

`brew install PHP`

`sudo pecl install grpc`

If you are having issues please visit one of these resources:

- https://github.com/grpc/grpc/tree/master/src/php
- https://grpc.io/docs/languages/php/quickstart/

### Composer

To check if you have composer installed, run the following command in your root directory:

`composer -V`

If the above does not work, please visit [here](https://getcomposer.org/download/) to install Composer. There are two methods, programmatically or manual file download. Please choose whichever way you prefer and then move the composer.phar file to your `$PATH` with the following command:

`mv composer.phar /usr/local/bin/composer`

### Update Repository Packages and Dependencies

In the php contrib repository, run the following four commands:

`make install`

Then run to make sure all dependencies and packages are up to date:

`make update`

Then run the following command to make sure the local composer is updated:

`composer update`

To make sure everything is working properly, run the following command to run all tests:

`make install && make update && make style && make test && make phan && make psalm && make phpstan`

At this point all necessary items have been installed in your system and you are ready to begin running the application.

## Running the Applications

### Run Collector

Run the following command. Make sure to replace `YOUR_ACCESS_KEY_HERE` and `YOUR_SECRET_ACCESS_KEY_HERE` with your own specific keys.

```console
docker run --rm -p 4317:4317 -p 55681:55681 -p 8889:8888 \
   -e AWS_REGION=us-west-2 \
   -e AWS_ACCESS_KEY_ID=YOUR_ACCESS_KEY_HERE \
   -e AWS_SECRET_ACCESS_KEY=YOUR_SECRET_ACCESS_KEY_HERE \
   -v "${PWD}/examples/aws/collector/config.yaml":/otel-local-config.yaml \
   --name awscollector public.ecr.aws/aws-observability/aws-otel-collector:latest \
   --config otel-local-config.yaml
```

In another terminal window, navigate to the opentelemetry-php-contrib folder.

To run `AwsClientApp`, navigate to `examples/aws/AwsClientApp`, then install required dependencies:

```
composer install
```

And run the following command:

`php bin/app`

The output for this app should look similar to the following:

```console
Starting Aws Client App

Which call would you like to make?
Type outgoing-http-call or aws-sdk-call
outgoing-http-call
Final trace ID: {"traceId":"1-622fb9fb-1b2031fcde9ac72610b6a0b9"}
Aws Client App complete!
```

Run the following command for Sample App 2:

`php examples/aws/SampleApp2/SampleApp2.php`

The output for this app should look similar to the following:

```console
Starting Sample App
Child span trace ID after service 2: {"traceId":"1-6115649a-230ef2ffe1d289a056b8d0ea"}
Sample App complete!
```

The trace IDs in any sample app will be completely unique. The first number is the version, the second section is the timestamp, and the last section is a randomized hexadecimal string.

## Viewing Traces on AWS X-Ray

Navigate to AWS X-Ray on your internet browser.

Click on the traces tab on the left hand side, like the image below:

<img width="1755" alt="Screen Shot 2021-08-12 at 11 22 23 AM" src="https://user-images.githubusercontent.com/46689344/129248717-a9fd9137-0ed5-4498-9cfb-8e3ba1a57fbe.png">

Make sure your region is set to us-west-2:

<img width="291" alt="Screen Shot 2021-08-12 at 11 21 59 AM" src="https://user-images.githubusercontent.com/46689344/129248725-d3f7a655-fe3b-47d4-a229-583365e16a54.png">

After running the sample app, there should be traces under the traces tab with all relevant information.

<img width="1398" alt="Screen Shot 2021-08-09 at 11 42 50 PM" src="https://user-images.githubusercontent.com/46689344/129248704-0888b387-2fa8-4753-824e-d99e0c9a67b6.png">
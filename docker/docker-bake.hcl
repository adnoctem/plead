# ==== Variables ====
# require setting a version
variable "VERSION" {
  default = null
}

# use 'latest' if no other TAGS are passed in
variable "TAGS" {
  default = "latest"
}

# targets to build (currently unused)
variable "TARGETS" {
  default = "dev,prod"
}

# determine (custom) image registries
variable "REGISTRIES" {
  default = "ghcr.io"
}

# lock the image repository
variable "REPOSITORY" {
  default = "adnoctem/plead"
}

# build for multiple PHP versions - can be a comma-separated list of values like 8.4,8.5 etc.
variable "PHP_VERSIONS" {
  default = "8.4,8.5"
}

# ==== Custom Functions ====
# common labels to add to ALL images
function "labels" {
  params = [php]
  result = {
    "org.opencontainers.image.base.name"     = "php:${php}-cli-alpine"
    "org.opencontainers.image.created"       = timestamp()
    "org.opencontainers.image.description"   = "CLI for managing Plesk-hosted mail resources (scheduled auto-replies) over XML-RPC and HTTP REST"
    "org.opencontainers.image.documentation" = "https://github.com/adnoctem/plead/wiki"
    "org.opencontainers.image.licenses"      = "MIT"
    "org.opencontainers.image.url"           = "https://github.com/adnoctem/plead"
    "org.opencontainers.image.source"        = "https://github.com/adnoctem/plead"
    "org.opencontainers.image.title"         = "plead"
    "org.opencontainers.image.vendor"        = "FMJ Studios"
    "org.opencontainers.image.authors"       = "info@fmj.studio"
    "org.opencontainers.image.version"       = VERSION == null ? "dev-${timestamp()}" : VERSION
  }
}

function "get_target" {
  params = []
  result = flatten(split(",", TARGETS))
}

function "get_php_version" {
  params = []
  result = flatten(split(",", PHP_VERSIONS))
}

# determine in which we're going to append for the image
function "get_tags" {
  params = []
  result = VERSION == null ? flatten(split(",", TAGS)) : concat(flatten(split(",", TAGS)), [VERSION])
}

# determine in which we're going to append for the image
function "get_registry" {
  params = []
  result = flatten(split(",", REGISTRIES))
}

# create the fully qualified tags
function "tags" {
  params = []
  result = flatten(concat(
    [for tag in get_tags() : "${REPOSITORY}:${tag}"],
    [for registry in get_registry() : [for tag in get_tags() : "${registry}/${REPOSITORY}:${tag}"]]
  ))
}

# ==== Bake Targets ====
group "default" {
  targets = ["plead"]
}

# The 'plead' Alpine CLI application image
target "plead" {
  name = "plead-php${replace(php, ".", "-")}"
  dockerfile = "docker/Dockerfile"
  context = "."
  matrix = {
    php = get_php_version()
  }
  args = {
    PHP_VERSION = php
  }
  platforms = [
    "linux/amd64",
    "linux/arm64",
    "linux/arm/v7",
    "linux/arm/v6",
    "linux/riscv64",
    "linux/s390x",
    "linux/386",
    "linux/ppc64le"
  ]
  tags = tags()
  labels = labels(php)
  output = ["type=docker"]
}
